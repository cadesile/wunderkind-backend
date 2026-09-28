<?php

declare(strict_types=1);

namespace App\Service\Competition;

/**
 * Pure interval math for the draw/reveal/resolve/intermission round cadence — no DB, no
 * entity dependencies, takes/returns primitives so it's testable as a plain TestCase.
 *
 * A round's time-budget (totalDurationSeconds / roundCount, unchanged from the original
 * single-pass formula) splits into a reveal window (fixtures published, resolution
 * pending) and an intermission (results published, next draw pending). Cron granularity
 * is 1 minute for both the draw and resolve passes, so a sub-minute window is invisible to
 * players — MIN_WINDOW_SECONDS floors the split, or skips it entirely for a round whose
 * total budget can't support both halves clearing the floor.
 */
final class CompetitionScheduleCalculator
{
    private const MIN_WINDOW_SECONDS = 120;

    /** @param positive-int $roundCount */
    public function intervalSeconds(int $totalDurationSeconds, int $roundCount): int
    {
        if ($roundCount < 1) {
            throw new \InvalidArgumentException('roundCount must be at least 1');
        }

        return $roundCount > 1 ? intdiv($totalDurationSeconds, $roundCount) : $totalDurationSeconds;
    }

    /**
     * Splits one round's budget into [revealSeconds, intermissionSeconds]. If the budget
     * can't clear MIN_WINDOW_SECONDS on both halves after the ratio split, the whole budget
     * goes to the reveal window and the intermission is skipped (prefer showing the fixture
     * over an imperceptible cooldown).
     *
     * @return array{0: int, 1: int}
     */
    public function splitInterval(int $intervalSeconds, float $intermissionRatio): array
    {
        if ($intervalSeconds < self::MIN_WINDOW_SECONDS * 2) {
            return [$intervalSeconds, 0];
        }

        $intermissionSeconds = (int) round($intervalSeconds * $intermissionRatio);
        $revealSeconds        = $intervalSeconds - $intermissionSeconds;

        if ($revealSeconds < self::MIN_WINDOW_SECONDS || $intermissionSeconds < self::MIN_WINDOW_SECONDS) {
            return [$intervalSeconds, 0];
        }

        return [$revealSeconds, $intermissionSeconds];
    }

    /** How long after locking before round 1's own draw becomes due — reuses round 1's own budget/ratio, not a second config value. */
    public function firstRoundLeadTimeSeconds(int $totalDurationSeconds, int $roundCount, float $intermissionRatio): int
    {
        $firstRoundInterval = $this->intervalSeconds($totalDurationSeconds, $roundCount);

        return (int) round($firstRoundInterval * $intermissionRatio);
    }
}
