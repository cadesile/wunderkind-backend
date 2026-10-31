<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Club;
use App\Entity\LeaderboardEntry;
use App\Entity\User;
use App\Enum\LeaderboardCategory;
use App\Service\Leaderboard\LeaderboardPeriod;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test of the growth mutator — no kernel, no database.
 *
 * observeCumulative() is the whole of growth scoring, and the three numbers it keeps are
 * the point: only the CUMULATIVE chains between periods, so the unsigned clamp on `score`
 * can never corrupt the next period's baseline.
 */
class LeaderboardEntryGrowthTest extends TestCase
{
    public function testFirstObservationStampsTheBaselineAndScoresZero(): void
    {
        $entry = $this->entry('2026-M10');

        self::assertNull($entry->getBaselineScore());
        self::assertNull($entry->getCumulativeScore());
        self::assertFalse($entry->hasBaseline());

        $entry->observeCumulative(100);

        self::assertSame(100, $entry->getBaselineScore());
        self::assertSame(100, $entry->getCumulativeScore());
        self::assertSame(0, $entry->getScore(), 'a club arriving with 100 career goals has grown by nothing yet');
        self::assertTrue($entry->hasBaseline());
    }

    public function testALaterObservationScoresTheDelta(): void
    {
        $entry = $this->entry('2026-M10');

        $entry->observeCumulative(100);
        $entry->observeCumulative(140);

        self::assertSame(100, $entry->getBaselineScore(), 'the baseline is stamped once and never moves');
        self::assertSame(140, $entry->getCumulativeScore());
        self::assertSame(40, $entry->getScore());
    }

    /**
     * THE case the third column exists for. club_reputation decays daily, so a cumulative
     * genuinely falls. score is BIGINT UNSIGNED, so storing a negative is a database error
     * — but if the clamp were the only record, baseline + score would stop equalling the
     * truth and the club would be permanently suppressed on the next observation.
     */
    public function testADecreaseClampsScoreWhileTheCumulativeStaysTruthful(): void
    {
        $entry = $this->entry('2026-M10');

        $entry->observeCumulative(500);   // baseline 500
        $entry->observeCumulative(460);   // decayed below the baseline

        self::assertSame(500, $entry->getBaselineScore());
        self::assertSame(460, $entry->getCumulativeScore(), 'the truthful cumulative is kept, not the clamp');
        self::assertSame(0, $entry->getScore());

        // And recovery is measured from the untouched baseline, not from the clamp.
        $entry->observeCumulative(520);

        self::assertSame(20, $entry->getScore());
    }

    /**
     * Growth must not compound its own clamping across periods: the NEXT period's baseline
     * comes from the cumulative, never from a previous growth value.
     */
    public function testTheNextPeriodBaselinesOnTheCumulativeNotTheClampedGrowth(): void
    {
        $october = $this->entry('2026-M10');
        $october->observeCumulative(500);
        $october->observeCumulative(460);   // score clamped to 0, cumulative 460

        $november = $this->entry('2026-M11');
        $november->observeCumulative($october->getCumulativeScore());
        $november->observeCumulative(500);

        self::assertSame(460, $november->getBaselineScore());
        self::assertSame(40, $november->getScore());
    }

    public function testObservingZeroIsAStatementNotANoOp(): void
    {
        $entry = $this->entry('2026-M10');

        $entry->observeCumulative(0);

        self::assertSame(0, $entry->getBaselineScore());
        self::assertSame(0, $entry->getCumulativeScore());
        self::assertSame(0, $entry->getScore());
        self::assertTrue($entry->hasBaseline(), 'a zero baseline is stamped, not treated as absent');
    }

    public function testSetScoreLeavesTheBaselineNullForAllTimeRows(): void
    {
        $entry = $this->entry(LeaderboardPeriod::ALL_TIME);

        $entry->setScore(12_345);

        self::assertSame(12_345, $entry->getScore());
        self::assertNull($entry->getBaselineScore(), "'all-time' is a standing and is never growth-scored");
        self::assertNull($entry->getCumulativeScore());
        self::assertFalse($entry->hasBaseline());
    }

    public function testObserveCumulativeTouchesUpdatedAt(): void
    {
        $entry  = $this->entry('2026-M10');
        $before = $entry->getUpdatedAt();

        usleep(1_500);
        $entry->observeCumulative(7);

        self::assertGreaterThanOrEqual($before, $entry->getUpdatedAt());
    }

    private function entry(string $period): LeaderboardEntry
    {
        $user = new User('growth@test.local');
        $club = new Club('Growth FC', $user);

        return new LeaderboardEntry($club, LeaderboardCategory::CLUB_GOALS, $period);
    }
}
