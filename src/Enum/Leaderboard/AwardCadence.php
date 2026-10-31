<?php

declare(strict_types=1);

namespace App\Enum\Leaderboard;

/**
 * How often a LeaderboardAwardDefinition closes a period and pays out.
 *
 * Deliberately NOT App\Enum\StatsPeriod, which is a *rolling-window* vocabulary for a
 * different board family (/api/stats/*) where `month` means "-30 days", not a calendar
 * month, and which carries cases (last_6_hours, season, all) that are meaningless here.
 * Sharing a type whose two consumers disagree on what `month` means is how
 * PeriodResolver-shaped bugs get written.
 *
 * The period-key string for a cadence is produced by LeaderboardPeriod::keyFor().
 */
enum AwardCadence: string
{
    case WEEKLY    = 'weekly';
    case MONTHLY   = 'monthly';
    case QUARTERLY = 'quarterly';

    public function label(): string
    {
        return match ($this) {
            self::WEEKLY    => 'Weekly',
            self::MONTHLY   => 'Monthly',
            self::QUARTERLY => 'Quarterly',
        };
    }
}
