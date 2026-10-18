<?php
namespace App\Enum\Appearance;

/**
 * Trophy silhouette, from the 16-bit trophy sprite
 * (assets/Components/trophy16/trophy16.ts's `TrophyStyle`).
 *
 * These replace the old `trophy-1`..`trophy-15` filename slugs, which named a
 * static SVG asset rather than a design: the sprite draws each silhouette
 * procedurally from a profile table, so a style is now a named shape the
 * backend and the app both understand. Values are the sprite's own ids and must
 * stay byte-identical to them — a config is handed to composeTrophySvg()
 * verbatim, with nothing in between to translate a renamed case.
 */
enum TrophyStyle: string
{
    case CLASSIC = 'classic';
    case GOBLET  = 'goblet';
    case LOVING  = 'loving';
    case URN     = 'urn';
    case CHALICE = 'chalice';
    case TALL    = 'tall';
    case WINGED  = 'winged';
    case LEAGUE  = 'league';
    case BALL    = 'ball';
    case BOWL    = 'bowl';

    /** Matches the sprite's own TROPHY_STYLES label list, so admin and app agree. */
    public function label(): string
    {
        return match ($this) {
            self::CLASSIC => 'Classic',
            self::GOBLET  => 'Goblet',
            self::LOVING  => 'Loving cup',
            self::URN     => 'Urn',
            self::CHALICE => 'Chalice',
            self::TALL    => 'Tall cup',
            self::WINGED  => 'Winged',
            self::LEAGUE  => 'League cup',
            self::BALL    => 'Ball',
            self::BOWL    => 'Bowl',
        };
    }

    /** True when the silhouette has handles — those carry ribbon knots rather than a stem bow. */
    public function hasHandles(): bool
    {
        return match ($this) {
            self::CLASSIC, self::GOBLET, self::LOVING, self::WINGED, self::LEAGUE => true,
            default => false,
        };
    }
}
