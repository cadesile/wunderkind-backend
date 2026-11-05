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
 */
class ScoutWageCalculatorTest extends TestCase
{
    public function testWageInterpolatesAcrossTheConfiguredRange(): void
    {
        $calculator = $this->calculator($this->config(2500, 7000));

        self::assertSame(2500, $calculator->weeklyWageFor(0), 'floor at zero experience');
        self::assertSame(7000, $calculator->weeklyWageFor(100), 'ceiling at full experience');
        self::assertSame(4750, $calculator->weeklyWageFor(50), 'midpoint');
    }

    /**
     * The same experience always prices the same.
     *
     * Load-bearing rather than obvious: Scout::$weeklySalary was added after every scout in the
     * world existed, so the backfill derives a wage from stored experience. A random roll would
     * make a backfilled scout and an identical new one disagree, and support unable to explain
     * either figure.
     */
    public function testTheSameExperienceAlwaysPricesTheSame(): void
    {
        $calculator = $this->calculator($this->config(2500, 7000));

        $first = $calculator->weeklyWageFor(63);

        for ($i = 0; $i < 25; $i++) {
            self::assertSame($first, $calculator->weeklyWageFor(63));
        }
    }

    /** A more experienced scout never costs less. */
    public function testWageIsMonotonicInExperience(): void
    {
        $calculator = $this->calculator($this->config(2500, 7000));

        $previous = -1;

        for ($experience = 0; $experience <= 100; $experience++) {
            $wage = $calculator->weeklyWageFor($experience);
            self::assertGreaterThanOrEqual($previous, $wage, "experience {$experience} priced below its predecessor");
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

    /** Experience outside 0-100 is clamped rather than extrapolated. */
    public function testExperienceOutsideTheScaleIsClamped(): void
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

    public function testScoutOverloadReadsTheEntitysOwnExperience(): void
    {
        $calculator = $this->calculator($this->config(2500, 7000));

        $scout = new Scout('Test Scout');
        $scout->setExperience(50);

        self::assertSame($calculator->weeklyWageFor(50), $calculator->weeklyWageForScout($scout));
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
