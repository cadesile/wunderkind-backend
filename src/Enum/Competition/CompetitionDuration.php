<?php

namespace App\Enum\Competition;

/**
 * How long a competition runs from lock to final, picked per CompetitionTemplate and copied
 * onto each ActiveCompetition instance.
 *
 * Declaration order is the admin dropdown's order (EasyAdmin renders the cases as-is), so
 * these are kept shortest-to-longest. Nothing indexes into cases(), so the order is a
 * presentation choice rather than something load-bearing — unlike TrophyStyle, where it is.
 *
 * The backing string is what lands in the database, so an existing case's value must not be
 * edited: `10h`, `24h`, `2d` and `1w` are already persisted on live template and competition
 * rows. New cases are purely additive.
 *
 * **The month cases are fixed 30/60-day windows, not calendar months.** A calendar `P1M`
 * would make toInterval() and toHours() disagree by up to a day depending on the month it
 * happened to start in — and toHours() is what GET /api/competitions serves to the client as
 * `durationHours`. Scheduling itself always measures real elapsed seconds between startsAt
 * and endsAt (CompetitionLockService), so it stays correct either way; this choice is about
 * the client's countdown matching the actual end time.
 */
enum CompetitionDuration: string
{
    case ONE_HOUR   = '1h';
    case TWO_HOURS  = '2h';
    case FOUR_HOURS = '4h';
    case SIX_HOURS  = '6h';
    case TEN_HOURS  = '10h';
    case ONE_DAY    = '24h';
    case TWO_DAYS   = '2d';
    case ONE_WEEK   = '1w';
    case ONE_MONTH  = '1mo';
    case TWO_MONTHS = '2mo';

    public function toInterval(): \DateInterval
    {
        return match ($this) {
            self::ONE_HOUR   => new \DateInterval('PT1H'),
            self::TWO_HOURS  => new \DateInterval('PT2H'),
            self::FOUR_HOURS => new \DateInterval('PT4H'),
            self::SIX_HOURS  => new \DateInterval('PT6H'),
            self::TEN_HOURS  => new \DateInterval('PT10H'),
            self::ONE_DAY    => new \DateInterval('P1D'),
            self::TWO_DAYS   => new \DateInterval('P2D'),
            self::ONE_WEEK   => new \DateInterval('P7D'),
            self::ONE_MONTH  => new \DateInterval('P30D'),
            self::TWO_MONTHS => new \DateInterval('P60D'),
        };
    }

    public function toHours(): int
    {
        return match ($this) {
            self::ONE_HOUR   => 1,
            self::TWO_HOURS  => 2,
            self::FOUR_HOURS => 4,
            self::SIX_HOURS  => 6,
            self::TEN_HOURS  => 10,
            self::ONE_DAY    => 24,
            self::TWO_DAYS   => 48,
            self::ONE_WEEK   => 168,
            self::ONE_MONTH  => 720,
            self::TWO_MONTHS => 1440,
        };
    }
}
