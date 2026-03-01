<?php

namespace App\Enum;

enum LeaderboardCategory: string
{
    case CAREER_EARNINGS    = 'career_earnings';
    case ACADEMY_REPUTATION = 'academy_reputation';
    case HALL_OF_FAME       = 'hall_of_fame';
}
