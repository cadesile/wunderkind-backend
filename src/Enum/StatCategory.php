<?php

namespace App\Enum;

enum StatCategory: string
{
    case MOST_TRANSFERS   = 'most_transfers';
    case MOST_DEVELOPMENT = 'most_development';
    case MOST_SEASONS     = 'most_seasons';
    case MOST_TROPHIES    = 'most_trophies';
}
