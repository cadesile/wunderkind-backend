<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which formula prices a generated player, staff member or scout.
 *
 * TWO CASES, AND `LEGACY` IS THE SHIPPED DEFAULT ON PURPOSE. The turnover model replaces the
 * wage bands and multipliers that have priced every row in the world so far, so the column
 * exists before the switch is thrown: the migration that adds it is a provable no-op, and
 * turning it on is an operator action with a form and a calibration preview in front of it.
 *
 * `LEGACY` IS NOT "THE OLD CODE LEFT LYING AROUND". It is a supported mode that must keep
 * producing byte-identical figures to what each generator produced before the unification —
 * `ability * random_int(contractValueRandMin, contractValueRandMax) * tierMultiplier`, with the
 * same `<` tier boundary and the same `(int)` truncation rather than rounding. Every write site
 * has a test asserting that, because "we collapsed four copies into one and nothing moved" is
 * only a claim until the numbers match.
 *
 * WHY THE FLIP IS NOT JUST A CONFIG CHANGE: cached world packs hold wages, and the pack cache
 * has no TTL. Flipping this changes pack CONTENT without changing pack SHAPE, so
 * WorldInitializationService::WORLD_PACK_VERSION has to be bumped in the same operator pass or
 * every already-cached country serves pre-flip wages forever. That constant's docblock is a
 * post-mortem of exactly that failure happening twice.
 */
enum EconomyWageModel: string
{
    /** Wage bands x ability x a random roll. What priced the world before 2026-11. */
    case LEGACY = 'legacy';

    /** Ability curve scaled so a reference squad costs a configured share of tier turnover. */
    case TURNOVER = 'turnover';

    public function label(): string
    {
        return match ($this) {
            self::LEGACY   => 'Legacy (bands x random roll)',
            self::TURNOVER => 'Turnover-anchored',
        };
    }
}
