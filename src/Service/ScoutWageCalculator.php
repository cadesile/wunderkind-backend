<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PoolConfig;
use App\Entity\Scout;
use App\Repository\PoolConfigRepository;

/**
 * What a scout costs per week, derived from the ABILITY already stored on the row.
 *
 * ⚠️ **IT WAS `experience` UNTIL A24, 2026-11-20, AND THE CHANGE IS AN OWNER RULING.** Asked
 * which scout number should drive a wage, the owner ruled: *"Give scouts a real ability and
 * price on that"* — `experience` goes back to meaning career length and scouting
 * effectiveness. A26 built the column this now reads and A27 backfilled it across every scout
 * in the world, and `ScoutAbilityCalculator`'s own docblock already named this as its consumer.
 *
 * **Why the change is not cosmetic, measured rather than argued.** `PoolConfig` generates
 * experience from `scoutExperienceMin/Max` = 4-34 on live config, while `ability` blends in a
 * judgement component drawn from 15-90. So an experience-driven wage reached only 30% of the
 * authored band and the top of it was unreachable by construction; an ability-driven one spans
 * roughly 12-47 on the same population. Interpolating on the figure the game means by "how
 * good is this scout" is also what makes this agree with the band ladder the app prices from
 * — see below.
 *
 * ⚠️ **THIS IS NO LONGER WHAT THE PLAYER SEES AT WORLD INIT.** Since A24 the app prices a
 * scout off `wageMultiplierTiers[].staffMultiplier` through the paying club's own tier scale,
 * exactly as it prices a coach (`tierStaffWeeklyWagePence` / `resolveScoutWagePence`). This
 * figure is the **tier-blind** one, and it is authoritative for exactly one population: the
 * shared hire-market pool served by `GET /api/market/data`, which is one row shown to every
 * country and so has no tier to be banded against — the same treatment a market COACH gets,
 * whose `weeklySalary` the app also maps straight through. `PoolConfig::$scoutWageMin/Max`
 * therefore still prices something; it is NOT dead config, contrary to A24's brief, and that
 * disagreement is recorded in `wunderkind-docs/economy-loose-ends/a24-wage-bands-report.md`.
 *
 * WHY DERIVED AND NOT ROLLED. `Scout::$weeklySalary` arrived long after every scout in the
 * world was generated, so the figure has to be computable from stored data or the backfill has
 * nothing to write. A random roll would also mean a backfilled scout and an identical
 * freshly-generated one disagreed on price for no reason a player could see. So generation
 * (ScoutGenerationService) and the backfill command call this one method and get the same
 * answer for the same ability.
 *
 * ⚠️ **AND IT IS A DERIVATION, NOT AN OVERRIDE, SINCE A24.** Owner ruling in the same pass:
 * `weeklySalary` gets the same `preUpdate` treatment `ability` has, so it re-derives on every
 * write and the admin help text no longer promises an override. See
 * `ScoutAbilityLifecycleSubscriber` — the test is whether a field is an OVERRIDE or a
 * DERIVATION, and the moment a wage is priced from a rule it is the latter.
 *
 * DETERMINISTIC AND MONOTONIC: a more able scout never costs less than a less able one. That is
 * the property the funding projection leans on — it is summed into a club's running costs, and
 * a non-monotonic wage would make "hire the better scout" sometimes cheaper, which reads as a
 * bug rather than a bargain. ⚠️ It is a property of THIS interpolation only: the app's band
 * ladder in front of it is not monotonic in the staff/player ratio
 * (0.500 -> 0.667 -> 0.533 -> 0.400 on live config), and the owner has ruled those cliffs are to
 * be measured on a real save before anything is re-authored.
 *
 * Ability is a 1-100 score (the column is unsigned with a floor of 1, and
 * `ScoutAbilityCalculator` clamps there), so the input is clamped to 0-100 before
 * interpolating. Everything is pence.
 */
final class ScoutWageCalculator
{
    /** Ability is a 1-100 score; the interpolation needs a fixed ceiling. */
    private const ABILITY_SCALE = 100;

    public function __construct(
        private readonly PoolConfigRepository $poolConfigRepository,
    ) {}

    /**
     * Straight-line interpolation from the configured floor to the configured ceiling.
     *
     * ⚠️ `$ability` is `Scout::$ability`, NOT `experience`. Mirrored byte-for-byte by the app's
     * `fallbackScoutWeeklyWagePence` (`src/utils/staffWages.ts`), deliberately, so a backfilled
     * scout and a freshly generated one never disagree on price. **Both sides changed input in
     * the same change; if one ever moves without the other, that parity is broken silently.**
     */
    public function weeklyWageFor(int $ability, ?PoolConfig $config = null): int
    {
        $config = $config ?? $this->poolConfigRepository->getConfig();

        // Normalised on read rather than trusted: the two columns are set independently in the
        // admin, so a transposed pair is a legitimate state of the row. Same reasoning as
        // StarterConfig::resolveUserStartingBalancePence().
        $min = min($config->getScoutWageMin(), $config->getScoutWageMax());
        $max = max($config->getScoutWageMin(), $config->getScoutWageMax());

        $clamped = max(0, min(self::ABILITY_SCALE, $ability));

        return (int) round($min + (($max - $min) * $clamped / self::ABILITY_SCALE));
    }

    /** Convenience for the backfill and for generation, which both hold the entity. */
    public function weeklyWageForScout(Scout $scout, ?PoolConfig $config = null): int
    {
        return $this->weeklyWageFor($scout->getAbility(), $config);
    }
}
