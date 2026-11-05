<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PoolConfig;
use App\Entity\Scout;
use App\Repository\PoolConfigRepository;

/**
 * What a scout costs per week, derived from the experience already stored on the row.
 *
 * WHY DERIVED AND NOT ROLLED. `Scout::$weeklySalary` arrived long after every scout in the
 * world was generated, so the figure has to be computable from stored data or the backfill has
 * nothing to write. A random roll would also mean a backfilled scout and an identical
 * freshly-generated one disagreed on price for no reason a player could see. So generation
 * (ScoutGenerationService) and the backfill command call this one method and get the same
 * answer for the same experience.
 *
 * DETERMINISTIC AND MONOTONIC: a more experienced scout never costs less than a less
 * experienced one. That is the property the funding projection leans on — it is summed into a
 * club's running costs, and a non-monotonic wage would make "hire the better scout" sometimes
 * cheaper, which reads as a bug rather than a bargain.
 *
 * Experience is a 0-100 score (PoolConfig::$scoutExperienceMin/Max bound what is generated, but
 * the column itself is unsigned and an admin can type anything), so the input is clamped to
 * 0-100 before interpolating. Everything is pence.
 */
final class ScoutWageCalculator
{
    /** Experience is documented as a 0-100 score; the interpolation needs a fixed ceiling. */
    private const EXPERIENCE_SCALE = 100;

    public function __construct(
        private readonly PoolConfigRepository $poolConfigRepository,
    ) {}

    /** Straight-line interpolation from the configured floor to the configured ceiling. */
    public function weeklyWageFor(int $experience, ?PoolConfig $config = null): int
    {
        $config = $config ?? $this->poolConfigRepository->getConfig();

        // Normalised on read rather than trusted: the two columns are set independently in the
        // admin, so a transposed pair is a legitimate state of the row. Same reasoning as
        // StarterConfig::resolveUserStartingBalancePence().
        $min = min($config->getScoutWageMin(), $config->getScoutWageMax());
        $max = max($config->getScoutWageMin(), $config->getScoutWageMax());

        $clamped = max(0, min(self::EXPERIENCE_SCALE, $experience));

        return (int) round($min + (($max - $min) * $clamped / self::EXPERIENCE_SCALE));
    }

    /** Convenience for the backfill and for generation, which both hold the entity. */
    public function weeklyWageForScout(Scout $scout, ?PoolConfig $config = null): int
    {
        return $this->weeklyWageFor($scout->getExperience(), $config);
    }
}
