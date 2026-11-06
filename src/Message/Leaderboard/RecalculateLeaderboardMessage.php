<?php

declare(strict_types=1);

namespace App\Message\Leaderboard;

/**
 * Recalculate one leaderboard: a category over a period.
 *
 * Dispatched by the admin list pages' batch actions. It carries the two
 * strings that name a board and nothing else — the handler re-reads current
 * state, so a replayed or duplicated delivery recomputes rather than
 * double-counting, and a message sitting in the queue cannot go stale.
 *
 * Note that the existing synchronous paths are untouched: the "generate
 * leaderboards" button and the cron still call LeaderboardCalculationService
 * directly. This is an additional entry point, not a replacement for them.
 */
final class RecalculateLeaderboardMessage
{
    public function __construct(
        /** LeaderboardCategory backing value. */
        public readonly string $category,
        /** "all-time", or an ISO week such as "2026-W34". */
        public readonly string $period,
    ) {}
}
