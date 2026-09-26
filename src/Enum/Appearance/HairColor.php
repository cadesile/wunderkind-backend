<?php
namespace App\Enum\Appearance;

/**
 * Unlike the old design, the sprite stores hairColor as a hex value directly
 * (stubble blends it numerically with the skin shade), so — mirroring how
 * SkinId's predecessor SkinTone worked — the enum value IS the hex.
 */
enum HairColor: string
{
    case BLACK      = '#1c1410';
    case DARK_BROWN = '#4a2c1a';
    case BROWN      = '#8a5a2b';
    case GINGER     = '#c8602a';
    case BLONDE     = '#e6bd55';
    case GREY       = '#d9d5cc';
}
