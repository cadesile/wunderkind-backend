<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Scout;
use App\Service\ScoutAbilityCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Scout ability.
 *
 * The properties that matter are the four the calculator's docblock commits to — DETERMINISM,
 * MONOTONICITY, the 1-100 CLAMP, and a mean over the keys PRESENT — plus one that is easy to
 * lose in a refactor and expensive to lose silently: EXACT-.5 ROUNDING PARITY with the SQL in
 * `Version20261120100000`, which did diverge on 6 of the 5,278 dev scouts before the integer
 * path landed.
 */
class ScoutAbilityCalculatorTest extends TestCase
{
    public function testBlendsExperienceAndJudgementMeanSeventyThirty(): void
    {
        $calculator = new ScoutAbilityCalculator();

        // 0.7 * 50 + 0.3 * 50 = 50 — equal inputs pass through whatever the weighting.
        self::assertSame(50, $calculator->abilityFor(50, $this->flat(50)));

        // 0.7 * 80 + 0.3 * 40 = 68 — experience dominates, which is the owner's ruling.
        self::assertSame(68, $calculator->abilityFor(80, $this->flat(40)));

        // 0.7 * 40 + 0.3 * 80 = 52 — and the mirror image is NOT the same figure.
        self::assertSame(52, $calculator->abilityFor(40, $this->flat(80)));
    }

    public function testIsDeterministic(): void
    {
        $calculator = new ScoutAbilityCalculator();
        $judgements = ['potential' => 61, 'technical' => 47, 'physical' => 23, 'mental' => 88, 'personality' => 34];

        $first = $calculator->abilityFor(29, $judgements);

        for ($i = 0; $i < 20; $i++) {
            self::assertSame($first, $calculator->abilityFor(29, $judgements), 'nothing is rolled here');
        }
    }

    public function testIsMonotonicInExperience(): void
    {
        $calculator = new ScoutAbilityCalculator();
        $previous   = 0;

        for ($experience = 1; $experience <= 100; $experience++) {
            $ability = $calculator->abilityFor($experience, $this->flat(50));
            self::assertGreaterThanOrEqual($previous, $ability, "experience {$experience} lowered ability");
            $previous = $ability;
        }
    }

    public function testIsMonotonicInJudgements(): void
    {
        $calculator = new ScoutAbilityCalculator();
        $previous   = 0;

        for ($judgement = 1; $judgement <= 100; $judgement++) {
            $ability = $calculator->abilityFor(50, $this->flat($judgement));
            self::assertGreaterThanOrEqual($previous, $ability, "judgement {$judgement} lowered ability");
            $previous = $ability;
        }
    }

    public function testClampsToOneThroughHundredAndNeverZero(): void
    {
        $calculator = new ScoutAbilityCalculator();

        // The column is unsigned but an admin can type anything into experience.
        self::assertSame(1, $calculator->abilityFor(0, $this->flat(0)), 'floor is 1, not 0');
        self::assertSame(1, $calculator->abilityFor(-500, $this->flat(-500)));
        self::assertSame(100, $calculator->abilityFor(900, $this->flat(900)));
        self::assertSame(1, $calculator->abilityFor(0, []), 'no judgements, no experience, still not 0');
    }

    /**
     * The 5-vs-7 question, pinned. A fixed divisor would under-rate every real scout by ~29%,
     * and the result would still look like a plausible number — which is why this is a test and
     * not a comment.
     */
    public function testMeansOverTheKeysPresentNotAFixedDivisor(): void
    {
        $calculator = new ScoutAbilityCalculator();

        $five  = ['potential' => 60, 'technical' => 60, 'physical' => 60, 'mental' => 60, 'personality' => 60];
        $seven = $five + ['youth' => 60, 'senior' => 60];

        self::assertSame(
            $calculator->abilityFor(40, $five),
            $calculator->abilityFor(40, $seven),
            'a 5-key row and a 7-key row of the same quality must read the same ability',
        );

        // And concretely: 0.7*40 + 0.3*60 = 46, not 0.7*40 + 0.3*(300/7) = 40.86 -> 41.
        self::assertSame(46, $calculator->abilityFor(40, $five));
    }

    public function testAnAbsentJudgementHalfFallsBackToExperienceNeverToZero(): void
    {
        $calculator = new ScoutAbilityCalculator();

        // The whole point: 0.7*60 + 0.3*0 = 42 would be the `?? 0` answer, and it is wrong.
        self::assertSame(60, $calculator->abilityFor(60, []));
        self::assertSame(60, $calculator->abilityFor(60, ['potential' => null, 'technical' => 'n/a']));
    }

    public function testSkipsNonNumericValuesRatherThanCountingThemAsZero(): void
    {
        $calculator = new ScoutAbilityCalculator();

        self::assertSame(
            $calculator->abilityFor(40, ['potential' => 60, 'technical' => 60]),
            $calculator->abilityFor(40, ['potential' => 60, 'technical' => 60, 'physical' => null]),
            'a null judgement is absent, not zero',
        );

        // JSON round-trips can hand back a numeric string; it is still a real judgement.
        self::assertSame(46, $calculator->abilityFor(40, ['potential' => '60', 'technical' => '60']));
    }

    /**
     * ⚠️ THE REGRESSION THIS FILE EXISTS FOR.
     *
     * Every case below has a true result of exactly k.5. `0.7 * e + 0.3 * m` in binary floats
     * lands just under on some of them, so the naive expression rounds DOWN where the
     * migration's NUMERIC arithmetic rounds UP. These five pairs are real dev-database rows
     * (experience / judgement mean) that disagreed, and the expected values are what the SQL
     * produced.
     *
     * If this test fails, PHP and the applied migration no longer agree about the same scout,
     * which is the two-sources-for-one-quantity defect the whole economy workstream is removing.
     * The migration is a historical record and cannot be edited; fix the calculator.
     */
    public function testRoundsExactHalvesTheSameWayTheMigrationSqlDoes(): void
    {
        $calculator = new ScoutAbilityCalculator();

        $cases = [
            [33, 38, 35],  // 34.5
            [25, 50, 33],  // 32.5
            [20, 75, 37],  // 36.5
            [31, 56, 39],  // 38.5
            [18, 43, 26],  // 25.5
            [4,  69, 24],  // 23.5
            [19, 64, 33],  // 32.5
            [30, 45, 35],  // 34.5
        ];

        foreach ($cases as [$experience, $judgementMean, $expected]) {
            self::assertSame(
                $expected,
                $calculator->abilityFor($experience, $this->flat($judgementMean)),
                "experience {$experience} / judgement mean {$judgementMean} must round half away from zero",
            );
        }
    }

    /**
     * The exact-integer path requires an integral SUM, not integral values, so a judgement set
     * whose mean is fractional must still round in step with the SQL.
     */
    public function testHandlesAFractionalJudgementMean(): void
    {
        $calculator = new ScoutAbilityCalculator();

        // sum 301 over 5 keys = 60.2; 0.7*40 + 0.3*60.2 = 46.06 -> 46.
        self::assertSame(46, $calculator->abilityFor(40, [
            'potential' => 61, 'technical' => 60, 'physical' => 60, 'mental' => 60, 'personality' => 60,
        ]));
    }

    public function testReadsTheEntityThroughTheConvenienceWrapper(): void
    {
        $calculator = new ScoutAbilityCalculator();

        $scout = new Scout('Pat Example');
        $scout->setExperience(80);
        $scout->setJudgements($this->flat(40));

        self::assertSame(
            $calculator->abilityFor(80, $this->flat(40)),
            $calculator->abilityForScout($scout),
            'the wrapper must not disagree with the method it wraps',
        );
    }

    /** The five keys `ScoutGenerationService` actually writes, all at one value. */
    private function flat(int $value): array
    {
        return [
            'potential'   => $value,
            'technical'   => $value,
            'physical'    => $value,
            'mental'      => $value,
            'personality' => $value,
        ];
    }
}
