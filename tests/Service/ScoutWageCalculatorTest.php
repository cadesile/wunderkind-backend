<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PoolConfig;
use App\Entity\Scout;
use App\Repository\PoolConfigRepository;
use App\Service\ScoutWageCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Scout wages.
 *
 * The properties that matter are DETERMINISM and MONOTONICITY, not the exact pence: generation
 * and the backfill command both call this, so the same scout must price the same either way, and
 * a better scout must never be cheaper than a worse one.
 *
 * ⚠️ **THE INPUT IS `ability`, NOT `experience`, SINCE A24 (2026-11-20)** — owner ruling. The
 * arithmetic is unchanged, so every value assertion below holds verbatim; what moved is which of
 * the two 0-100 numbers is fed in, and `testScoutOverloadReadsTheEntitysOwnAbility` is the one
 * that catches the wrong one. See `ScoutWageCalculator`'s docblock for why.
 */
class ScoutWageCalculatorTest extends TestCase
{
    public function testWageInterpolatesAcrossTheConfiguredRange(): void
    {
        $calculator = $this->calculator($this->config(2500, 7000));

        self::assertSame(2500, $calculator->weeklyWageFor(0), 'floor at zero ability');
        self::assertSame(7000, $calculator->weeklyWageFor(100), 'ceiling at full ability');
        self::assertSame(4750, $calculator->weeklyWageFor(50), 'midpoint');
    }

    /**
     * The same ability always prices the same.
     *
     * Load-bearing rather than obvious: Scout::$weeklySalary was added after every scout in the
     * world existed, so the backfill derives a wage from what is stored. A random roll would
     * make a backfilled scout and an identical new one disagree, and support unable to explain
     * either figure.
     */
    public function testTheSameAbilityAlwaysPricesTheSame(): void
    {
        $calculator = $this->calculator($this->config(2500, 7000));

        $first = $calculator->weeklyWageFor(63);

        for ($i = 0; $i < 25; $i++) {
            self::assertSame($first, $calculator->weeklyWageFor(63));
        }
    }

    /**
     * A more able scout never costs less.
     *
     * ⚠️ A property of THIS interpolation only. Since A24 the app's band ladder sits in front of
     * it and is NOT monotonic in the staff/player ratio — the owner has ruled those cliffs are to
     * be measured on a real save before anything is re-authored. Pinned app-side in
     * `src/__tests__/economy/scoutWageBands.test.ts`.
     */
    public function testWageIsMonotonicInAbility(): void
    {
        $calculator = $this->calculator($this->config(2500, 7000));

        $previous = -1;

        for ($ability = 0; $ability <= 100; $ability++) {
            $wage = $calculator->weeklyWageFor($ability);
            self::assertGreaterThanOrEqual($previous, $wage, "ability {$ability} priced below its predecessor");
            $previous = $wage;
        }
    }

    /**
     * A transposed range still yields a figure inside it.
     *
     * The two columns are set independently in the admin, so min > max is a legitimate state of
     * the row — the same reason StarterConfig normalises its grant range on read rather than
     * refusing the form.
     */
    public function testATransposedRangeIsNormalisedOnRead(): void
    {
        $calculator = $this->calculator($this->config(7000, 2500));

        self::assertSame(2500, $calculator->weeklyWageFor(0));
        self::assertSame(7000, $calculator->weeklyWageFor(100));
    }

    /** Ability outside 0-100 is clamped rather than extrapolated. */
    public function testAbilityOutsideTheScaleIsClamped(): void
    {
        $calculator = $this->calculator($this->config(2500, 7000));

        self::assertSame(2500, $calculator->weeklyWageFor(-40));
        self::assertSame(7000, $calculator->weeklyWageFor(10_000));
    }

    /** A range with both ends equal is a fixed wage, not an error. */
    public function testAFixedRangeGivesEveryScoutTheSameWage(): void
    {
        $calculator = $this->calculator($this->config(3000, 3000));

        self::assertSame(3000, $calculator->weeklyWageFor(0));
        self::assertSame(3000, $calculator->weeklyWageFor(100));
    }

    /**
     * 🔴 THE A24 REGRESSION GUARD. The entity overload must read `ability`, not `experience`.
     *
     * They are different quantities on the same 0-100 scale, so the wrong one produces a
     * perfectly plausible wage that disagrees with every other scout in the game — which is why
     * this test sets them to DIFFERENT values rather than the one number the old test used.
     */
    public function testScoutOverloadReadsTheEntitysOwnAbility(): void
    {
        $calculator = $this->calculator($this->config(2500, 7000));

        $scout = new Scout('Test Scout');
        $scout->setExperience(50);
        $scout->setAbility(30);

        self::assertSame($calculator->weeklyWageFor(30), $calculator->weeklyWageForScout($scout));
        self::assertNotSame($calculator->weeklyWageFor(50), $calculator->weeklyWageForScout($scout));
    }

    private function config(int $min, int $max): PoolConfig
    {
        $config = new PoolConfig();
        $config->setScoutWageMin($min);
        $config->setScoutWageMax($max);

        return $config;
    }

    private function calculator(PoolConfig $config): ScoutWageCalculator
    {
        $repo = $this->createStub(PoolConfigRepository::class);
        $repo->method('getConfig')->willReturn($config);

        return new ScoutWageCalculator($repo);
    }
}
