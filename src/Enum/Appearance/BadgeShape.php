<?php
namespace App\Enum\Appearance;

enum BadgeShape: string
{
    case SHIELD  = 'shield';
    case CREST   = 'crest';
    case ROUND   = 'round';
    case DIAMOND = 'diamond';
    case HEX     = 'hex';
}
