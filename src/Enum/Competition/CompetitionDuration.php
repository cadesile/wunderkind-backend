<?php

namespace App\Enum\Competition;

enum CompetitionDuration: string
{
    case TEN_HOURS = '10h';
    case ONE_DAY   = '24h';
    case TWO_DAYS  = '2d';
    case ONE_WEEK  = '1w';

    public function toInterval(): \DateInterval
    {
        return match ($this) {
            self::TEN_HOURS => new \DateInterval('PT10H'),
            self::ONE_DAY   => new \DateInterval('P1D'),
            self::TWO_DAYS  => new \DateInterval('P2D'),
            self::ONE_WEEK  => new \DateInterval('P7D'),
        };
    }
}
