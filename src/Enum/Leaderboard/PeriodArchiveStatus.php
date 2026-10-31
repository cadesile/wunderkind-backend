<?php

declare(strict_types=1);

namespace App\Enum\Leaderboard;

/**
 * Lifecycle of one LeaderboardPeriodArchive row.
 *
 * `pending` is the CLAIM: the row is inserted pending, before any work is done, and its
 * presence is what stops a second process closing the same period. The two terminal states
 * are set on the entity so they land in the same flush as the work they describe — per
 * CLAUDE.md's "never commit a round's status ahead of the work it describes". A row left
 * pending past its lease is therefore the signal that something failed mid-close.
 */
enum PeriodArchiveStatus: string
{
    /** Claimed, work in progress. Reclaimable once the claim lease expires. */
    case PENDING = 'pending';

    /** Closed with a frozen board, and possibly awards. */
    case ARCHIVED = 'archived';

    /** Closed deliberately with nothing to record — an empty board, or no eligible club. */
    case SKIPPED = 'skipped';

    public function isTerminal(): bool
    {
        return $this !== self::PENDING;
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING  => 'Pending',
            self::ARCHIVED => 'Archived',
            self::SKIPPED  => 'Skipped',
        };
    }
}
