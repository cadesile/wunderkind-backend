<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GameConfig;
use PHPUnit\Framework\TestCase;

/**
 * The deficit ladder expressed in weeks of wages.
 *
 * ── WHAT IS ACTUALLY AT RISK HERE ─────────────────────────────────────────────────────────
 *
 * Two things, and they pull in opposite directions.
 *
 * **Null must survive.** The app carries this ladder as a built-in constant, so null means "use
 * yours". Anything that turns null into `[]` — a helpful default, an `array` type hint, an
 * eager normaliser — silently switches the mechanic off on every box while looking like
 * configuration. That is the failure nobody would notice until a season rolled over.
 *
 * **A repaired ladder must stay repaired.** The setter sorts and makes rows contiguous, and
 * `ConfigImportExportCoverageTest::testDefaultsRoundTripUnchanged` exports, re-imports and
 * re-exports. A normaliser that keeps moving fails that test from a distance, with an error
 * that names neither this column nor this file. Idempotency is pinned here instead.
 *
 * The row contract is CONTIGUOUS, not merely non-overlapping: each `weeksMin` equals the
 * previous `weeksMax`, so the upper bound reads as exclusive and every deficit lands in exactly
 * one row.
 */
class BankruptcyDeductionWeeksTest extends TestCase
{
    /** The shape agreed with the app team, as `docs/economy-threshold-review.md` specifies it. */
    private const AGREED = [
        ['weeksMin' => 1,  'weeksMax' => 2,    'points' => 3],
        ['weeksMin' => 2,  'weeksMax' => 4,    'points' => 6],
        ['weeksMin' => 4,  'weeksMax' => 8,    'points' => 10],
        ['weeksMin' => 8,  'weeksMax' => 16,   'points' => 20],
        ['weeksMin' => 16, 'weeksMax' => null, 'points' => 50],
    ];

    // ── Null is the shipped state, and it has to be reachable ────────────

    public function testItShipsAsNull(): void
    {
        // The inert state. An old build sees a key it does not read; a new build sees "no
        // ladder configured" and uses its own constant.
        self::assertNull((new GameConfig())->getBankruptcyDeductionWeeks());
    }

    public function testAnEmptyArrayCollapsesToNull(): void
    {
        // `[]` and "no ladder" are indistinguishable, and only one of them is a thing somebody
        // authors. A surviving `[]` would read as a real ladder with no rungs — deductions off,
        // dressed as configuration.
        self::assertNull((new GameConfig())->setBankruptcyDeductionWeeks([])->getBankruptcyDeductionWeeks());
    }

    public function testItCanBeClearedAgainAfterAuthoring(): void
    {
        // The operator's only route back to the shipped state. A one-way door here would make
        // "use the app's ladder" unauthorable the moment anyone saved anything.
        $config = (new GameConfig())->setBankruptcyDeductionWeeks(self::AGREED);
        self::assertNotNull($config->getBankruptcyDeductionWeeks());

        self::assertNull($config->setBankruptcyDeductionWeeks(null)->getBankruptcyDeductionWeeks());
    }

    public function testARowsetThatIsEntirelyMalformedBecomesNullNotEmpty(): void
    {
        // Everything dropped is the same answer as nothing authored. Returning `[]` here would
        // publish an empty ladder off the back of a typo.
        $config = (new GameConfig())->setBankruptcyDeductionWeeks([
            ['weekMin' => 1, 'points' => 3],   // key typo
            'not a row',
            42,
        ]);

        self::assertNull($config->getBankruptcyDeductionWeeks());
    }

    // ── The agreed ladder survives untouched ─────────────────────────────

    public function testTheAgreedLadderRoundTripsByteIdentical(): void
    {
        // THE ONE THAT MATTERS MOST. The shape was agreed with the app team; if the setter
        // rewrites it, the two sides disagree about a mechanic neither can see.
        self::assertSame(
            self::AGREED,
            (new GameConfig())->setBankruptcyDeductionWeeks(self::AGREED)->getBankruptcyDeductionWeeks(),
        );
    }

    public function testNormalisationIsIdempotent(): void
    {
        // Pinned here rather than discovered through ConfigImportExportCoverageTest, which
        // would fail with an error naming neither this column nor this file.
        $once  = (new GameConfig())->setBankruptcyDeductionWeeks(self::AGREED)->getBankruptcyDeductionWeeks();
        $twice = (new GameConfig())->setBankruptcyDeductionWeeks($once)->getBankruptcyDeductionWeeks();

        self::assertSame($once, $twice);
    }

    public function testEvenAMessyLadderIsIdempotentOnceRepaired(): void
    {
        // The repair must converge in one pass, not oscillate.
        $messy = [
            ['weeksMin' => 16, 'weeksMax' => null, 'points' => 50],
            ['weeksMin' => 4,  'weeksMax' => 9,    'points' => 10],
            ['weeksMin' => 1,  'weeksMax' => 3,    'points' => 3],
        ];

        $once  = (new GameConfig())->setBankruptcyDeductionWeeks($messy)->getBankruptcyDeductionWeeks();
        $twice = (new GameConfig())->setBankruptcyDeductionWeeks($once)->getBankruptcyDeductionWeeks();

        self::assertSame($once, $twice);
    }

    // ── The repairs ──────────────────────────────────────────────────────

    public function testRowsAreSortedAscending(): void
    {
        // The order typed into a textarea does not have to be right, the same courtesy
        // setNpcSolvencyThresholds() extends.
        $rows = (new GameConfig())->setBankruptcyDeductionWeeks([
            ['weeksMin' => 8, 'weeksMax' => 16, 'points' => 20],
            ['weeksMin' => 1, 'weeksMax' => 2,  'points' => 3],
            ['weeksMin' => 4, 'weeksMax' => 8,  'points' => 10],
        ])->getBankruptcyDeductionWeeks();

        self::assertSame([1, 4, 8], array_column($rows, 'weeksMin'));
    }

    public function testAnUnboundedRowIsSortedLastWhereverItWasTyped(): void
    {
        $rows = (new GameConfig())->setBankruptcyDeductionWeeks([
            ['weeksMin' => 16, 'weeksMax' => null, 'points' => 50],
            ['weeksMin' => 1,  'weeksMax' => 2,    'points' => 3],
        ])->getBankruptcyDeductionWeeks();

        self::assertNull($rows[array_key_last($rows)]['weeksMax']);
        self::assertSame(1, $rows[0]['weeksMin']);
    }

    public function testGapsAreClosedSoNoDeficitFallsThrough(): void
    {
        // A deficit of 3 weeks must land somewhere. Left as typed, 1-2 then 4-8 swallows it.
        $rows = (new GameConfig())->setBankruptcyDeductionWeeks([
            ['weeksMin' => 1, 'weeksMax' => 2, 'points' => 3],
            ['weeksMin' => 4, 'weeksMax' => 8, 'points' => 10],
        ])->getBankruptcyDeductionWeeks();

        foreach ($rows as $i => $row) {
            if ($i === 0) {
                continue;
            }
            self::assertSame($rows[$i - 1]['weeksMax'], $row['weeksMin'], "row {$i} must start where the one before ended");
        }
    }

    public function testOverlapsAreClosedTheSameWay(): void
    {
        $rows = (new GameConfig())->setBankruptcyDeductionWeeks([
            ['weeksMin' => 1, 'weeksMax' => 6, 'points' => 3],
            ['weeksMin' => 4, 'weeksMax' => 8, 'points' => 10],
        ])->getBankruptcyDeductionWeeks();

        self::assertSame($rows[0]['weeksMax'], $rows[1]['weeksMin']);
    }

    public function testEverythingBehindAnUnboundedRowIsDropped(): void
    {
        // Unreachable under any resolution rule — the exact defect bankruptcyDeductionTiers has
        // and cannot have fixed, because that column is stored verbatim.
        $rows = (new GameConfig())->setBankruptcyDeductionWeeks([
            ['weeksMin' => 1, 'weeksMax' => null, 'points' => 3],
            ['weeksMin' => 4, 'weeksMax' => null, 'points' => 10],
            ['weeksMin' => 8, 'weeksMax' => null, 'points' => 20],
        ])->getBankruptcyDeductionWeeks();

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['weeksMax']);
    }

    public function testATransposedPairIsNormalisedRatherThanDropped(): void
    {
        // A legitimate state of a hand-edited JSON column, same as the staff role bands.
        $rows = (new GameConfig())->setBankruptcyDeductionWeeks([
            ['weeksMin' => 8, 'weeksMax' => 2, 'points' => 6],
        ])->getBankruptcyDeductionWeeks();

        self::assertSame(2, $rows[0]['weeksMin']);
        self::assertSame(8, $rows[0]['weeksMax']);
    }

    public function testAnEmptyWindowIsDropped(): void
    {
        $rows = (new GameConfig())->setBankruptcyDeductionWeeks([
            ['weeksMin' => 4, 'weeksMax' => 4,    'points' => 6],
            ['weeksMin' => 1, 'weeksMax' => null, 'points' => 3],
        ])->getBankruptcyDeductionWeeks();

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['weeksMin']);
    }

    public function testMalformedRowsAreDroppedWithoutTakingTheLadder(): void
    {
        // A season transition must not die on a mistyped admin field — the same totality
        // `League::setParachute()` and the staff bands keep.
        $rows = (new GameConfig())->setBankruptcyDeductionWeeks([
            ['weeksMin' => 1, 'weeksMax' => 2, 'points' => 3],
            ['points' => 99],                       // no weeksMin
            'nonsense',
            ['weeksMin' => 'x', 'points' => 1],     // non-numeric
            ['weeksMin' => 2, 'weeksMax' => null, 'points' => 50],
        ])->getBankruptcyDeductionWeeks();

        self::assertCount(2, $rows);
        self::assertSame([1, 2], array_column($rows, 'weeksMin'));
    }

    public function testNegativesAreClampedAndMissingPointsBecomeZero(): void
    {
        $rows = (new GameConfig())->setBankruptcyDeductionWeeks([
            ['weeksMin' => -5, 'weeksMax' => null],
        ])->getBankruptcyDeductionWeeks();

        self::assertSame(0, $rows[0]['weeksMin']);
        self::assertSame(0, $rows[0]['points']);
    }

    public function testNumericStringsAreAccepted(): void
    {
        // A JSON textarea is the input and quoting a number is an easy slip.
        $rows = (new GameConfig())->setBankruptcyDeductionWeeks([
            ['weeksMin' => '1', 'weeksMax' => '2', 'points' => '3'],
        ])->getBankruptcyDeductionWeeks();

        self::assertSame(['weeksMin' => 1, 'weeksMax' => 2, 'points' => 3], $rows[0]);
    }

    // ── The old ladder is untouched ──────────────────────────────────────

    public function testTheOldPenceLadderIsUnaffected(): void
    {
        // Additive means additive: shipped builds read bankruptcyDeductionTiers and it keeps
        // its own values, its own shape and its own verbatim storage.
        $config = new GameConfig();
        $before = $config->getBankruptcyDeductionTiers();

        $config->setBankruptcyDeductionWeeks(self::AGREED);

        self::assertSame($before, $config->getBankruptcyDeductionTiers());
        self::assertArrayHasKey('deficitMin', $config->getBankruptcyDeductionTiers()[0]);
    }
}
