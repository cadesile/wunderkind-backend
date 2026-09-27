<?php
namespace App\Enum\Appearance;

/** Player-only — ignored when rendering a staff outfit. */
enum KitStyle: string
{
    case PLAIN   = 'plain';
    case STRIPES = 'stripes';
    case HOOPS   = 'hoops';
    case HALVES  = 'halves';
    case SASH    = 'sash';
    case BAND    = 'band';
    case SLEEVES = 'sleeves';
}
