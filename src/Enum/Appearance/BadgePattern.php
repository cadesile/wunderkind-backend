<?php
namespace App\Enum\Appearance;

enum BadgePattern: string
{
    case PLAIN    = 'plain';
    case STRIPES  = 'stripes';
    case HOOPS    = 'hoops';
    case HALVES   = 'halves';
    case QUARTERS = 'quarters';
    case CHEVRON  = 'chevron';
}
