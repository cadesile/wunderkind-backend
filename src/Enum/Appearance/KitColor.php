<?php
namespace App\Enum\Appearance;

/**
 * Used for both `primary` and `secondary` — the sprite has one shared kit
 * palette for player kits and staff outfits alike (no separate muted/vibrant
 * split like the old design's player-vs-staff trim colors).
 */
enum KitColor: string
{
    case RED         = '#c8202f';
    case MAROON      = '#7a1f2b';
    case ORANGE      = '#f07a1a';
    case YELLOW      = '#f2c230';
    case GREEN       = '#1f8a4c';
    case DARK_GREEN  = '#0f4d33';
    case SKY_BLUE    = '#7fb8e6';
    case ROYAL_BLUE  = '#1f4fb8';
    case NAVY        = '#1b2a4a';
    case PURPLE      = '#5b2c83';
    case WHITE       = '#f4f3ee';
    case BLACK       = '#1a1a1a';
}
