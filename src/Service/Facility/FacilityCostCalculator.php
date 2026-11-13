<?php

declare(strict_types=1);

namespace App\Service\Facility;

use App\Entity\FacilityTemplate;

/**
 * The facility cost curve, stated once.
 *
 * `FacilityTemplate::$baseCost` carries the canonical rule in its own docblock — "Frontend
 * formula: (currentLevel + 1) x baseCost" per upgrade — and that sentence is the contract the
 * client prices its BUILD screen against. Reaching level L from nothing therefore costs
 * baseCost x (1 + 2 + ... + L) = baseCost x L(L+1)/2.
 *
 * WHY THIS IS A CLASS AND NOT AN EXPRESSION AT EACH CALL SITE. The arithmetic now has three
 * consumers that must agree to the penny:
 *
 *  - ClubSetupService charges it, from the account's ledger credit;
 *  - ClubValuationService reports it back as sunk investment, which a land sell-back reads;
 *  - the client computes it ahead of the charge to show a price.
 *
 * If the charge and the valuation ever disagreed, a player would be billed one figure and
 * credited against another — so the two server-side readers share this one implementation
 * rather than each restating the triangular sum.
 *
 * EVERY FIGURE IS PENCE, and every method is pure arithmetic on stored template values. Nothing
 * here queries, rounds to currency, or consults config: a price has to be reproducible from the
 * row months later, which is the same requirement StarterConfig's deterministic grant has.
 */
final class FacilityCostCalculator
{
    /**
     * Cost of taking one facility from `$fromLevel` to `$toLevel`.
     *
     * Levels are clamped at zero and the range is normalised, so a "downgrade" costs nothing
     * rather than refunding: a lower target is an idempotent re-post of an older BUILD, not a
     * sale. Callers that need to refuse a downgrade do so on the levels, not on the price.
     */
    public function costBetween(int $baseCost, int $fromLevel, int $toLevel): int
    {
        $from = max(0, $fromLevel);
        $to   = max(0, $toLevel);

        if ($to <= $from) {
            return 0;
        }

        // sum_{L=from+1}^{to} L x baseCost, closed form so a 5-level site is one multiplication
        // rather than a loop whose rounding could drift from the client's.
        return (int) ($baseCost * (($to * ($to + 1)) - ($from * ($from + 1))) / 2);
    }

    /** Cost of reaching `$level` from unbuilt. The figure ClubValuationService calls sunk. */
    public function costToReach(int $baseCost, int $level): int
    {
        return $this->costBetween($baseCost, 0, $level);
    }

    /** Cost of the next single upgrade, or 0 at max level. */
    public function nextUpgradeCost(FacilityTemplate $template, int $currentLevel): int
    {
        if ($currentLevel >= $template->getMaxLevel()) {
            return 0;
        }

        return $this->costBetween($template->getBaseCost(), $currentLevel, $currentLevel + 1);
    }

    /**
     * Weekly upkeep of one facility at a given level: `weeklyUpkeepBase x 1.5^level`.
     *
     * ALIGNED TO THE APP, 2026-11-12, AND THIS REVERSES WHAT THIS DOCBLOCK USED TO ARGUE.
     * The exponent was `level - 1`, resolved that way because `FacilityTemplate`'s own column
     * comment calls `weeklyUpkeepBase` the cost "at level 1" while its formula line says
     * `1.5^level` — two readings one power of 1.5 apart, and the "at level 1" sentence looked
     * like the one describing the stored value.
     *
     * THE APP IS THE TIE-BREAKER, NOT THE PROSE. The device is the game engine and it charges
     * `base x 1.5^level`, so the server's `level - 1` made its own figure 1.5x cheaper than
     * what the player actually pays. A projection that understates a running cost is worse
     * than one that is merely differently documented: it is the number POST /api/club/fund
     * returns for the funding screen to warn with, so being low is being wrong in the
     * direction that lets a player underfund a club. `weeklyUpkeepBase` is therefore the
     * cost at level 0 — a hypothetical, since an unbuilt facility is short-circuited below —
     * and level 1 pays 1.5x it.
     *
     * CLIENT-VISIBLE CONSEQUENCE: `projection.facilityUpkeepPerWeekPence` and
     * `projection.facilityUpkeepPence` on POST /api/club/fund both rise exactly 50%. Same
     * keys, changed values, which `.context/shared/api-backwards-compatibility.md` counts as a
     * deliberate break; it is logged there with its consumer check.
     *
     * ── STILL LOW ABOVE THE BOTTOM TIER, AND DELIBERATELY SO ──────────────────────────────
     *
     * Fixing the exponent closed the 1.5x gap; a second one remains. The device multiplies
     * upkeep by a TIER FACTOR rising to roughly 3x at tier 1, and nothing here does — this
     * method never sees a tier, and neither `FacilityCostCalculator` nor `ClubFundingService`
     * injects `EconomyScaleResolver` at all. The published projection is therefore exact only
     * in the bottom division and understates by up to 3x above it.
     *
     * By the same argument made above, that is being wrong in the direction that lets a player
     * underfund a club. It is accepted rather than fixed because the tier factor is the
     * device's model: reproducing it here would make the backend a second author of a curve
     * only the app executes, and the two would drift the first time either moved. The caller's
     * docblock labels the shortfall instead.
     */
    public function weeklyUpkeep(FacilityTemplate $template, int $level): int
    {
        if ($level <= 0 || $template->getWeeklyUpkeepBase() <= 0) {
            return 0;
        }

        return (int) round($template->getWeeklyUpkeepBase() * (1.5 ** $level));
    }
}
