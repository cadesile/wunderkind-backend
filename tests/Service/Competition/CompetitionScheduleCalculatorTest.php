<?php

declare(strict_types=1);

namespace App\Tests\Service\Competition;

use App\Enum\Competition\CompetitionDuration;
use App\Service\Competition\CompetitionScheduleCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CompetitionScheduleCalculatorTest extends TestCase
{
    private CompetitionScheduleCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CompetitionScheduleCalculator();
    }

    /** @return array<string, array{0: int, 1: int}> */
    public static function durationAndRoundCountProvider(): array
    {
        $cases = [];
        foreach ([
            'TEN_HOURS' => CompetitionDuration::TEN_HOURS,
            'ONE_DAY'   => CompetitionDuration::ONE_DAY,
            'TWO_DAYS'  => CompetitionDuration::TWO_DAYS,
            'ONE_WEEK'  => CompetitionDuration::ONE_WEEK,
        ] as $durationName => $duration) {
            $totalSeconds = (new \DateTimeImmutable())->add($duration->toInterval())->getTimestamp()
                - (new \DateTimeImmutable())->getTimestamp();
            foreach ([2, 3, 4, 5, 6] as $roundCount) {
                $cases["{$durationName} / {$roundCount} rounds"] = [$totalSeconds, $roundCount];
            }
        }

        return $cases;
    }

    #[DataProvider('durationAndRoundCountProvider')]
    public function testSplitIntervalSumsBackToTheFullInterval(int $totalSeconds, int $roundCount): void
    {
        $intervalSeconds = $this->calculator->intervalSeconds($totalSeconds, $roundCount);
        $this->assertSame(intdiv($totalSeconds, $roundCount), $intervalSeconds);

        [$reveal, $intermission] = $this->calculator->splitInterval($intervalSeconds, 0.3);
        $this->assertSame($intervalSeconds, $reveal + $intermission);
        $this->assertGreaterThanOrEqual(0, $reveal);
        $this->assertGreaterThanOrEqual(0, $intermission);
    }

    #[DataProvider('durationAndRoundCountProvider')]
    public function testDefaultRatioPinnedNumericOutputClearsTheFloor(int $totalSeconds, int $roundCount): void
    {
        $intervalSeconds = $this->calculator->intervalSeconds($totalSeconds, $roundCount);
        [$reveal, $intermission] = $this->calculator->splitInterval($intervalSeconds, 0.3);

        // Every real duration x round-count combination (10h..1w, 2..6 rounds) comfortably
        // clears the floor at the default ratio — the floor exists defensively for a future
        // shorter duration/larger capacity, not because today's matrix needs it.
        $this->assertGreaterThan(0, $intermission, "Expected a real intermission window for {$totalSeconds}s / {$roundCount} rounds");
        $this->assertSame((int) round($intervalSeconds * 0.3), $intermission);
        $this->assertSame($intervalSeconds - $intermission, $reveal);
    }

    public function testIntervalSecondsGuardsRoundCountBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->intervalSeconds(3600, 0);
    }

    public function testIntervalSecondsSingleRoundUsesTheWholeBudget(): void
    {
        // Not reachable via BracketLabeler in practice (min capacity 4 -> 2 rounds), but the
        // calculator guards it defensively.
        $this->assertSame(3600, $this->calculator->intervalSeconds(3600, 1));
    }

    public function testNonIntegerDivisibleTotalAbsorbsTheRemainderWithoutGoingNegative(): void
    {
        // 100000 / 3 = 33333.33... — a synthetic total chosen specifically to not divide evenly.
        $intervalSeconds = $this->calculator->intervalSeconds(100_000, 3);
        $this->assertSame(33_333, $intervalSeconds);

        [$reveal, $intermission] = $this->calculator->splitInterval($intervalSeconds, 0.3);
        $this->assertSame($intervalSeconds, $reveal + $intermission);
        $this->assertGreaterThanOrEqual(0, $reveal);
        $this->assertGreaterThanOrEqual(0, $intermission);
    }

    public function testSubFloorBudgetSkipsTheSplitEntirely(): void
    {
        // 200s total can't clear MIN_WINDOW_SECONDS (120s) on both halves at any ratio.
        [$reveal, $intermission] = $this->calculator->splitInterval(200, 0.3);
        $this->assertSame(200, $reveal);
        $this->assertSame(0, $intermission);
    }

    public function testJustAboveFloorBudgetStillSkipsIfOneHalfWouldMiss(): void
    {
        // 300s at ratio 0.3 -> intermission 90s (below the 120s floor) -> skip entirely.
        [$reveal, $intermission] = $this->calculator->splitInterval(300, 0.3);
        $this->assertSame(300, $reveal);
        $this->assertSame(0, $intermission);
    }

    public function testComfortablyAboveFloorBudgetSplitsNormally(): void
    {
        // 1000s at ratio 0.3 -> intermission 300s, reveal 700s — both clear the 120s floor.
        [$reveal, $intermission] = $this->calculator->splitInterval(1000, 0.3);
        $this->assertSame(700, $reveal);
        $this->assertSame(300, $intermission);
    }

    #[DataProvider('durationAndRoundCountProvider')]
    public function testFirstRoundLeadTimeEqualsRoundOneBudgetTimesRatio(int $totalSeconds, int $roundCount): void
    {
        $expectedInterval = $this->calculator->intervalSeconds($totalSeconds, $roundCount);
        $expectedLeadTime = (int) round($expectedInterval * 0.3);

        $this->assertSame($expectedLeadTime, $this->calculator->firstRoundLeadTimeSeconds($totalSeconds, $roundCount, 0.3));
    }
}
