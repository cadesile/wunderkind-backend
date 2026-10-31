<?php
namespace App\Enum\Appearance;

/**
 * Staff-only — ignored when rendering a player kit.
 *
 * The four below JUMPER were added by the sprite's V4 drop (2026-10-31). Two of
 * them change what skin the sprite leaves bare, which is the only reason that
 * matters here: POLO and SHIRT show the forearms, and SHIRT and RAIN hide the
 * neck.
 */
enum Outfit: string
{
    case COAT   = 'coat';
    case TRACK  = 'track';
    case SUIT   = 'suit';
    case JUMPER = 'jumper';
    case POLO   = 'polo';
    case SHIRT  = 'shirt';
    case GILET  = 'gilet';
    case RAIN   = 'rain';
}
