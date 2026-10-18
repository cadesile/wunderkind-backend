<?php
namespace App\Enum\Appearance;

/**
 * Trophy metal finish, from the 16-bit trophy sprite
 * (assets/Components/trophy16/trophy16.ts's `TrophyFinish`).
 *
 * Deliberately the same three choices the removed App\Enum\TrophyColour offered,
 * so no admin loses an option in the move to the new component — only the
 * two-tone case is renamed, from `gold_silver` to the sprite's own `twotone`.
 * In that finish the cup body is silver while the rims, bands, foot rim, knot,
 * handles, finial, medallion, plaques and ball are gold; the sprite decides
 * which part is which, so there is nothing further to configure.
 */
enum TrophyFinish: string
{
    case GOLD    = 'gold';
    case TWOTONE = 'twotone';
    case SILVER  = 'silver';

    /** Matches the sprite's own TROPHY_FINISHES label list. */
    public function label(): string
    {
        return match ($this) {
            self::GOLD    => 'Gold',
            self::TWOTONE => 'Gold & silver',
            self::SILVER  => 'Silver',
        };
    }
}
