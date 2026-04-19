<?php

namespace App\Enum;

enum LeaderboardCategory: string
{
    case CAREER_EARNINGS = 'career_earnings';
    case CLUB_REPUTATION = 'club_reputation';
    case HALL_OF_FAME    = 'hall_of_fame';
}
