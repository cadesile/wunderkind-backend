<?php

declare(strict_types=1);

namespace App\Tests\Service\Leaderboard;

use App\Enum\Leaderboard\AwardCadence;
use App\Service\Leaderboard\LeaderboardPeriod;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test — LeaderboardPeriod is all-static and dependency-free, so no kernel
 * and no database.
 */
class LeaderboardPeriodTest extends TestCase
{
    /**
     * THE non-regression pin for the four de-duplicated call sites (SyncService,
     * GenerateLeaderboardsCommand x2, DecayReputationCommand). If currentWeekKey() ever
     * stops being byte-identical to the raw expression those sites used, every existing
     * ISO-week leaderboard row silently orphans.
     */
    public function testCurrentWeekKeyIsByteIdenticalToTheRawExpression(): void
    {
        $dates = [
            '2026-12-30 12:00:00', // inside ISO week 53 of a 53-week year
            '2027-01-01 12:00:00', // Jan 1 belongs to the PREVIOUS ISO year (2026-W53)
            '2026-01-01 12:00:00', // Jan 1 belongs to its own ISO year here
            '2021-01-01 12:00:00', // Jan 1 belongs to 2020-W53
            '2026-10-31 23:59:59',
        ];

        foreach ($dates as $date) {
            $at = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));

            self::assertSame(
                $at->format('o-\WW'),
                LeaderboardPeriod::currentWeekKey($at),
                "currentWeekKey() diverged from format('o-\\WW') for {$date}",
            );
        }

        // And with no argument at all, which is how every call site uses it.
        self::assertSame((new \DateTimeImmutable())->format('o-\WW'), LeaderboardPeriod::currentWeekKey());
    }

    public function testKeyForEachCadence(): void
    {
        $at = new \DateTimeImmutable('2026-10-31 12:00:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-W44', LeaderboardPeriod::keyFor(AwardCadence::WEEKLY, $at));
        self::assertSame('2026-M10', LeaderboardPeriod::keyFor(AwardCadence::MONTHLY, $at));
        self::assertSame('2026-Q4', LeaderboardPeriod::keyFor(AwardCadence::QUARTERLY, $at));
    }

    /** Pins leaderboard_entry.period's varchar(20). */
    public function testEveryKeyFitsTheColumn(): void
    {
        self::assertLessThanOrEqual(20, strlen(LeaderboardPeriod::ALL_TIME));

        for ($i = 0; $i < 400; $i++) {
            $at = (new \DateTimeImmutable('2025-12-01', new \DateTimeZone('UTC')))->modify("+{$i} days");

            foreach (AwardCadence::cases() as $cadence) {
                self::assertLessThanOrEqual(20, strlen(LeaderboardPeriod::keyFor($cadence, $at)));
            }
        }
    }

    /**
     * The close job's monotonic claim guard is `last_closed_period_key < :dueKey`, a plain
     * string comparison — so zero-padding is load-bearing.
     */
    public function testKeysSortLexicographicallyWithinACadence(): void
    {
        self::assertTrue('2026-M09' < '2026-M10');
        self::assertTrue('2026-W09' < '2026-W44');
        self::assertTrue('2026-Q1' < '2026-Q4');
        self::assertTrue('2025-M12' < '2026-M01');

        foreach (AwardCadence::cases() as $cadence) {
            $key = LeaderboardPeriod::keyFor($cadence, new \DateTimeImmutable('2026-01-15', new \DateTimeZone('UTC')));

            for ($i = 0; $i < 30; $i++) {
                $next = LeaderboardPeriod::nextKey($key);
                self::assertNotNull($next);
                self::assertTrue($key < $next, "{$key} should sort before {$next}");
                $key = $next;
            }
        }
    }

    public function testBoundsRoundTripAcrossAYear(): void
    {
        for ($i = 0; $i < 400; $i++) {
            $at = (new \DateTimeImmutable('2025-12-01 09:30:00', new \DateTimeZone('UTC')))->modify("+{$i} days");

            foreach (AwardCadence::cases() as $cadence) {
                $key    = LeaderboardPeriod::keyFor($cadence, $at);
                $bounds = LeaderboardPeriod::boundsOf($key);

                self::assertNotNull($bounds, "no bounds for {$key}");
                self::assertSame($key, LeaderboardPeriod::keyFor($cadence, $bounds[0]));
                self::assertLessThanOrEqual($at, $bounds[0], "{$key} start is after the date that produced it");
                self::assertGreaterThan($at, $bounds[1], "{$key} end is not exclusive-after the date that produced it");
            }
        }
    }

    public function testWeeklyBoundsStartMondayMidnightUtc(): void
    {
        $bounds = LeaderboardPeriod::boundsOf('2026-W44');

        self::assertNotNull($bounds);
        self::assertSame('2026-10-26 00:00:00', $bounds[0]->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $bounds[0]->getTimezone()->getName());
        self::assertSame('Monday', $bounds[0]->format('l'));
        self::assertSame('2026-11-02 00:00:00', $bounds[1]->format('Y-m-d H:i:s'));
    }

    public function testQuarterlyBoundsAreThreeWholeMonths(): void
    {
        $bounds = LeaderboardPeriod::boundsOf('2026-Q4');

        self::assertNotNull($bounds);
        self::assertSame('2026-10-01 00:00:00', $bounds[0]->format('Y-m-d H:i:s'));
        self::assertSame('2027-01-01 00:00:00', $bounds[1]->format('Y-m-d H:i:s'));
    }

    public function testMonthlyBoundsAreOneWholeMonth(): void
    {
        $bounds = LeaderboardPeriod::boundsOf('2026-M02');

        self::assertNotNull($bounds);
        self::assertSame('2026-02-01 00:00:00', $bounds[0]->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-01 00:00:00', $bounds[1]->format('Y-m-d H:i:s'));
    }

    public function testPreviousKeyCrossesYearBoundaries(): void
    {
        // 2025 is a 52-week ISO year, so the week before 2026-W01 is 2025-W52 — not W53.
        // 2020 IS a 53-week year, which is the case that actually exercises the boundary.
        self::assertSame('2025-W52', LeaderboardPeriod::previousKey('2026-W01'));
        self::assertSame('2020-W53', LeaderboardPeriod::previousKey('2021-W01'));
        self::assertSame('2025-Q4', LeaderboardPeriod::previousKey('2026-Q1'));
        self::assertSame('2025-M12', LeaderboardPeriod::previousKey('2026-M01'));
    }

    public function testNextKeyCrossesYearBoundaries(): void
    {
        self::assertSame('2027-W01', LeaderboardPeriod::nextKey('2026-W53'));
        self::assertSame('2027-Q1', LeaderboardPeriod::nextKey('2026-Q4'));
        self::assertSame('2027-M01', LeaderboardPeriod::nextKey('2026-M12'));
    }

    public function testCadenceOfAllTimeIsNull(): void
    {
        self::assertNull(LeaderboardPeriod::cadenceOf(LeaderboardPeriod::ALL_TIME));
        self::assertTrue(LeaderboardPeriod::isValidKey(LeaderboardPeriod::ALL_TIME));
    }

    public function testCadenceOfRecognisesEachFormat(): void
    {
        self::assertSame(AwardCadence::WEEKLY, LeaderboardPeriod::cadenceOf('2026-W44'));
        self::assertSame(AwardCadence::MONTHLY, LeaderboardPeriod::cadenceOf('2026-M10'));
        self::assertSame(AwardCadence::QUARTERLY, LeaderboardPeriod::cadenceOf('2026-Q4'));
    }

    public function testIsValidKeyRejectsJunk(): void
    {
        foreach (['', 'nonsense', '2026-W99', '2026-W00', '2026-M13', '2026-M00', '2026-Q0', '2026-Q5',
            'all time', '2026', '2026-W4', 'ALL-TIME', '2026-w44', "2026-W44\n"] as $key) {
            self::assertFalse(LeaderboardPeriod::isValidKey($key), "'{$key}' should be rejected");
            self::assertNull(LeaderboardPeriod::boundsOf($key));
            self::assertNull(LeaderboardPeriod::nextKey($key));
            self::assertNull(LeaderboardPeriod::previousKey($key));
        }
    }

    /**
     * 2025 has 52 ISO weeks and 2026 has 53, so a well-formed week number can still name
     * no real period. setISODate() silently rolls it into the next ISO year, which is why
     * cadenceOf() round-trips rather than only pattern-matching.
     */
    public function testWeekFiftyThreeIsOnlyValidInAFiftyThreeWeekYear(): void
    {
        self::assertTrue(LeaderboardPeriod::isValidKey('2026-W53'));
        self::assertTrue(LeaderboardPeriod::isValidKey('2020-W53'));
        self::assertFalse(LeaderboardPeriod::isValidKey('2025-W53'));
    }

    public function testLabels(): void
    {
        self::assertSame('Week 44, 2026', LeaderboardPeriod::label('2026-W44'));
        self::assertSame('Week 9, 2026', LeaderboardPeriod::label('2026-W09'));
        self::assertSame('October 2026', LeaderboardPeriod::label('2026-M10'));
        self::assertSame('Q4 2026', LeaderboardPeriod::label('2026-Q4'));
        self::assertSame('All time', LeaderboardPeriod::label(LeaderboardPeriod::ALL_TIME));
        self::assertSame('nonsense', LeaderboardPeriod::label('nonsense'));
    }
}
