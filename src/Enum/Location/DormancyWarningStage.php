<?php

namespace App\Enum\Location;

/**
 * How far through the dormancy countdown a warning was sent.
 *
 * Two stages rather than one, because a single warning is easy to miss and the consequence is
 * losing a plot. They are recorded separately so "we warned them twice" is a fact on record
 * rather than an assumption — a support ticket about a reclaimed plot is answered by these rows.
 */
enum DormancyWarningStage: string
{
    /** Seven days before the plot would be reclaimed. */
    case N_MINUS_7 = 'n_minus_7';

    /** The day before. */
    case N_MINUS_1 = 'n_minus_1';

    /**
     * Days of inactivity after which a plot is reclaimed.
     *
     * The single definition of the dormancy policy. Both stage thresholds are DERIVED from it
     * below rather than written out, so moving the deadline cannot leave a warning firing after
     * the reclaim it was supposed to precede.
     */
    public const RECLAIM_AFTER_DAYS = 30;

    /** How many days of grace remain when this stage fires. */
    public function daysRemaining(): int
    {
        return match ($this) {
            self::N_MINUS_7 => 7,
            self::N_MINUS_1 => 1,
        };
    }

    /** Days of inactivity at which this stage fires — 23 and 29 against a 30-day deadline. */
    public function triggersAfterDays(): int
    {
        return self::RECLAIM_AFTER_DAYS - $this->daysRemaining();
    }

    /**
     * The stage a club this inactive should be warned at, or null if it is too early.
     *
     * Returns the MOST URGENT applicable stage. If a cron tick was missed and a club is already
     * at 29 days, it gets the one-day warning rather than a stale seven-day one — the player
     * needs the truth, not the warning they should have had a week ago.
     */
    public static function forDaysInactive(int $daysInactive): ?self
    {
        foreach ([self::N_MINUS_1, self::N_MINUS_7] as $stage) {
            if ($daysInactive >= $stage->triggersAfterDays()) {
                return $stage;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::N_MINUS_7 => '7 days left',
            self::N_MINUS_1 => '1 day left',
        };
    }
}
