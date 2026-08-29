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
    case CLUB_GOALS        = 'club_goals';
    case CLUB_ASSISTS      = 'club_assists';
    case IRON_MAN          = 'iron_man';
    case TRANSFER_RECORD   = 'transfer_record';
    case TRANSFER_SPEND    = 'transfer_spend';

    /**
     * True for categories whose score requires a cross-table aggregate
     * (ClubFacility / PlayerCareerStat / SeasonRecord / Transfer) rather than a plain Club scalar.
     * These are computed only by LeaderboardCalculationService, not on every sync.
     */
    public function isAggregate(): bool
    {
        return match ($this) {
            self::EMPIRE_INDEX, self::GOLDEN_BOOT, self::PLAYMAKER, self::HALL_OF_FAME,
            self::CLUB_GOALS, self::CLUB_ASSISTS, self::IRON_MAN,
            self::TRANSFER_RECORD, self::TRANSFER_SPEND => true,
            default => false,
        };
    }
}
