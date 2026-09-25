<?php
namespace App\Enum\Appearance;

/** Staff-only — ignored when rendering a player kit. */
enum Outfit: string
{
    case COAT  = 'coat';
    case TRACK = 'track';
    case SUIT  = 'suit';
    case JUMPER = 'jumper';
}
