<?php
namespace App\Enum\Appearance;

/**
 * Replaces the old hex-backed SkinTone: the sprite identifies skin by an id and
 * draws it with two shades (a base fill and a darker shade for ears/neck/brows),
 * not a single hex. Cases are ordered lightest → darkest — WorldRegion's
 * skinWeights() and WorldRegionTest both depend on this exact order.
 */
enum SkinId: string
{
    case S1 = 's1';
    case S2 = 's2';
    case S3 = 's3';
    case S4 = 's4';
    case S5 = 's5';
    case S6 = 's6';

    /** Base fill color. */
    public function baseColor(): string
    {
        return match ($this) {
            self::S1 => '#f6d3b3',
            self::S2 => '#ecc095',
            self::S3 => '#d49c64',
            self::S4 => '#b37548',
            self::S5 => '#8a5230',
            self::S6 => '#5c3822',
        };
    }

    /** Darker shade used for ears, neck, and brows. */
    public function shadeColor(): string
    {
        return match ($this) {
            self::S1 => '#e0b28c',
            self::S2 => '#d39f73',
            self::S3 => '#b9804c',
            self::S4 => '#965d36',
            self::S5 => '#704023',
            self::S6 => '#472a18',
        };
    }
}
