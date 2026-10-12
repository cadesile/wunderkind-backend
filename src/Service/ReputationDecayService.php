<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;

/**
 * Dormancy-based reputation decay: a club loses POINTS_PER_DAY reputation for every
 * whole day it goes without syncing.
 *
 * Reputation is otherwise client-authoritative — SyncService overwrites
 * Club::$reputation from the sync payload on every sync (the device is the game
 * engine; see that method's own comment). This decay is therefore deliberately
 * *transient*: it drags a silent club down the reputation board, and the owner's
 * next sync re-asserts their on-device figure and restores it. That is the intended
 * behaviour, confirmed with the product owner — the penalty is for being absent, not
 * a permanent mark. Do not "fix" it by subtracting an accumulated total on sync.
 *
 * Every rule here is pure arithmetic over one Club so it can be unit-tested without a
 * database; the command owns iteration, batching and persistence.
 */
class ReputationDecayService
{
    /** Reputation lost per whole day without a sync. */
    public const POINTS_PER_DAY = 5;

    private const SECONDS_PER_DAY = 86400;

    /**
     * Whole days of dormancy this club has not yet been charged for.
     *
     * Measured from the latest of three anchors, so the result is correct no matter
     * how the command is scheduled:
     *  - lastSyncedAt     — the club is active up to this moment
     *  - reputationDecayedAt — decay already charged up to this moment
     *  - createdAt        — a club that has never synced still starts its clock
     *                       at founding, not at the epoch
     *
     * Whole days only: a club 23 hours silent owes nothing, and the 23 hours are not
     * lost either — they carry because advanceDecayAnchor() moves the anchor forward
     * by exactly the days charged, never to "now".
     */
    public function dormantDays(Club $club, \DateTimeImmutable $now): int
    {
        $elapsed = $now->getTimestamp() - $this->anchor($club)->getTimestamp();

        return $elapsed <= 0 ? 0 : intdiv($elapsed, self::SECONDS_PER_DAY);
    }

    /**
     * Applies decay to one club, returning the reputation actually lost (0 when the
     * club is not yet a whole day silent, or is already at the floor).
     *
     * Floors at 0 — `club.reputation` is an unsigned bigint, so a negative value is
     * not merely undesirable, it would fail to persist.
     */
    public function apply(Club $club, \DateTimeImmutable $now): int
    {
        $days = $this->dormantDays($club, $now);
        if ($days <= 0) {
            return 0;
        }

        $before = $club->getReputation();
        $after  = max(0, $before - ($days * self::POINTS_PER_DAY));

        $club->setReputation($after);

        // Advanced even when nothing was lost (a club already at 0), so the next run
        // doesn't re-walk the same dormant days for every floored club forever.
        $club->setReputationDecayedAt($this->advanceDecayAnchor($club, $days));

        return $before - $after;
    }

    /** The point in time this club's unbilled dormancy is measured from. */
    private function anchor(Club $club): \DateTimeImmutable
    {
        $candidates = array_filter([
            $club->getLastSyncedAt(),
            $club->getReputationDecayedAt(),
            $club->getCreatedAt(),
        ]);

        return max($candidates);
    }

    /**
     * The anchor moved forward by exactly the days charged — not to `now`, which
     * would silently discard the part-day remainder on every run and let a club
     * drift a few hours of credit per execution.
     */
    private function advanceDecayAnchor(Club $club, int $days): \DateTimeImmutable
    {
        return $this->anchor($club)->modify(sprintf('+%d days', $days));
    }
}
