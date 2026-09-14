<?php

namespace App\Enum;

enum StatCategory: string
{
    case MOST_TRANSFERS    = 'most_transfers';
    case MOST_DEVELOPMENT  = 'most_development';
    case MOST_SEASONS      = 'most_seasons';
    case MOST_TROPHIES     = 'most_trophies';
    case BEST_FORM         = 'best_form';
    case BIGGEST_ROUT      = 'biggest_rout';
    case SUPER_STRIKER     = 'super_striker';
    case FORTRESS_DEFENCE  = 'fortress_defence';
    case TRANSFER_SPLURGE  = 'transfer_splurge';
}
