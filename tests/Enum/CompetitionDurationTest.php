<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Competition\CompetitionDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CompetitionDuration carries the same fact twice — once as a \DateInterval (what actually
 * sets a competition's endsAt) and once as an hour count (what GET /api/competitions serves
 * to the client as `durationHours`). Two hand-maintained match arms over the same case list
 * is exactly the shape that drifts, and neither mistake is caught by the compiler: a case
 * missing from an arm is an UnhandledMatchError at runtime, and a wrong number in one arm is
 * silent — the client would just count down to the wrong time.
 */
class CompetitionDurationTest extends TestCase
{
    /** @return array<string, array{0: CompetitionDuration}> */
    public static function durationProvider(): array
    {
        $cases = [];
        foreach (CompetitionDuration::cases() as $duration) {
            $cases[$duration->name] = [$duration];
        }

        return $cases;
    }

    #[DataProvider('durationProvider')]
    public function testIntervalAndHoursDescribeTheSameLength(CompetitionDuration $duration): void
    {
        $now     = new \DateTimeImmutable('2026-01-15 12:00:00');
        $seconds = $now->add($duration->toInterval())->getTimestamp() - $now->getTimestamp();

        $this->assertSame(
            $duration->toHours() * 3600,
            $seconds,
            sprintf(
                '%s: toInterval() and toHours() disagree — the client would count down to a '
                . 'different time than the server ends the competition at.',
                $duration->name,
            ),
        );
    }

    /**
     * The month cases are deliberately fixed 30/60-day windows rather than calendar months,
     * so that the two accessors above can agree at all — see the enum's own docblock. A
     * calendar P1M would make this test fail in February, which is the point of pinning it.
     */
    public function testMonthCasesAreFixedWindowsNotCalendarMonths(): void
    {
        $february = new \DateTimeImmutable('2026-02-01 00:00:00');

        $this->assertSame(
            '2026-03-03',
            $february->add(CompetitionDuration::ONE_MONTH->toInterval())->format('Y-m-d'),
            'ONE_MONTH must advance a fixed 30 days, not land on 1 March',
        );
        $this->assertSame(720, CompetitionDuration::ONE_MONTH->toHours());
        $this->assertSame(1440, CompetitionDuration::TWO_MONTHS->toHours());
    }

    /**
     * The backing strings are persisted on live `competition_template` and
     * `active_competition` rows, so editing one orphans every row holding it. New cases are
     * additive; these four are load-bearing history.
     */
    public function testTheAlreadyPersistedBackingValuesAreUnchanged(): void
    {
        $this->assertSame('10h', CompetitionDuration::TEN_HOURS->value);
        $this->assertSame('24h', CompetitionDuration::ONE_DAY->value);
        $this->assertSame('2d', CompetitionDuration::TWO_DAYS->value);
        $this->assertSame('1w', CompetitionDuration::ONE_WEEK->value);
    }

    public function testEveryCaseHasADistinctBackingValue(): void
    {
        $values = array_column(CompetitionDuration::cases(), 'value');

        $this->assertSame(array_values(array_unique($values)), $values);
    }

    /** The dropdown is rendered straight off cases(), so the order is the order admins see. */
    public function testCasesAreOrderedShortestToLongest(): void
    {
        $hours  = array_map(static fn (CompetitionDuration $d): int => $d->toHours(), CompetitionDuration::cases());
        $sorted = $hours;
        sort($sorted);

        $this->assertSame($sorted, $hours, 'cases() order is what the admin Duration dropdown shows');
    }
}
