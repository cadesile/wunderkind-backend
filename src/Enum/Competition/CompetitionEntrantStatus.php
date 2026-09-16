<?php

namespace App\Enum\Competition;

enum CompetitionEntrantStatus: string
{
    case REGISTERED = 'registered';
    case ACTIVE      = 'active';
    case ELIMINATED  = 'eliminated';
    case WINNER       = 'winner';
    case WITHDRAWN    = 'withdrawn';
}
