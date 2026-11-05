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
     * Weekly upkeep of one facility at a given level.
     *
     * `FacilityTemplate::$weeklyUpkeepBase` is documented as "base weekly upkeep cost in pence
     * AT LEVEL 1", with a growth factor of 1.5 per level. Level 1 therefore pays the base
     * itself, which is why the exponent is `level - 1` and not `level`: the latter would make
     * level 1 cost 1.5x the figure the column says it is.
     *
     * THE COLUMN'S OWN DOCBLOCK WRITES THE FORMULA AS "baseCost x 1.5^level", WHICH DISAGREES
     * with its "at level 1" sentence by one power of 1.5. Resolved here in favour of "at level
     * 1" because that describes the stored value, while the formula line names a column
     * (`baseCost`) that is not the upkeep column at all and reads as a copy-paste. This only
     * feeds the funding floor below — it is not a client-visible price — but if the weekly tick
     * ever starts charging upkeep, the two have to be reconciled deliberately.
     */
    public function weeklyUpkeep(FacilityTemplate $template, int $level): int
    {
        if ($level <= 0 || $template->getWeeklyUpkeepBase() <= 0) {
            return 0;
        }

        return (int) round($template->getWeeklyUpkeepBase() * (1.5 ** ($level - 1)));
    }
}
