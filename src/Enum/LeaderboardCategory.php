<?php

namespace App\Enum;

enum LeaderboardCategory: string
{
    case CAREER_EARNINGS = 'career_earnings';
    case CLUB_REPUTATION = 'club_reputation';
    case HALL_OF_FAME    = 'hall_of_fame';
    case GOLDEN_BOOT      = 'golden_boot';
    case PLAYMAKER        = 'playmaker';
    case EMPIRE_INDEX      = 'empire_index';
    case FANATICS          = 'fanatics';

    /**
     * True for categories whose score requires a cross-table aggregate
     * (ClubFacility / PlayerCareerStat) rather than a plain Club scalar.
     * These are computed only by LeaderboardCalculationService, not on every sync.
     */
    public function isAggregate(): bool
    {
        return match ($this) {
            self::EMPIRE_INDEX, self::GOLDEN_BOOT, self::PLAYMAKER => true,
            default => false,
        };
    }
}
