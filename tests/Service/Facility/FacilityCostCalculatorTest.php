<?php

declare(strict_types=1);

namespace App\Tests\Service\Facility;

use App\Entity\FacilityTemplate;
use App\Service\Facility\FacilityCostCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The facility cost curve.
 *
 * These assertions are the contract three things depend on agreeing: the BUILD charge, the club
 * valuation a sell-back pays out against, and the price the client shows before tapping. The
 * formula is published on FacilityTemplate::$baseCost as "(currentLevel + 1) x baseCost" per
 * upgrade, so the numbers below are checked against that sentence rather than against the
 * implementation.
 */
class FacilityCostCalculatorTest extends TestCase
{
    private FacilityCostCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new FacilityCostCalculator();
    }

    /**
     * Each upgrade costs (currentLevel + 1) x baseCost — the published formula, stepped one
     * level at a time.
     */
    public function testOneUpgradeCostsTheNextLevelTimesBase(): void
    {
        foreach ([0 => 100, 1 => 200, 2 => 300, 3 => 400, 4 => 500] as $from => $expected) {
            self::assertSame(
                $expected,
                $this->calculator->costBetween(100, $from, $from + 1),
                sprintf('upgrade %d -> %d', $from, $from + 1),
            );
        }
    }

    /** Reaching level L from nothing is the triangular sum baseCost x L(L+1)/2. */
    public function testReachingALevelIsTheTriangularSum(): void
    {
        self::assertSame(100,  $this->calculator->costToReach(100, 1));
        self::assertSame(300,  $this->calculator->costToReach(100, 2));
        self::assertSame(600,  $this->calculator->costToReach(100, 3));
        self::assertSame(1000, $this->calculator->costToReach(100, 4));
        self::assertSame(1500, $this->calculator->costToReach(100, 5));
    }

    /**
     * A partial build then the rest costs the same as building it all at once.
     *
     * THE PROPERTY THE BUILD CHARGE RELIES ON. ClubFacilityBuildService prices from the level a
     * facility is ALREADY at, so if stepping cost more (or less) than going straight there, a
     * player who built a site in two passes would pay a different total from one who did it in
     * one — and the valuation, which only knows the final level, would disagree with both.
     */
    public function testPartialBuildsSumToTheSameTotal(): void
    {
        $direct = $this->calculator->costToReach(250, 5);

        $stepped = $this->calculator->costBetween(250, 0, 2)
            + $this->calculator->costBetween(250, 2, 4)
            + $this->calculator->costBetween(250, 4, 5);

        self::assertSame($direct, $stepped);
    }

    /** Level 0 is free, and so is asking for a level already held. */
    public function testNothingToBuildCostsNothing(): void
    {
        self::assertSame(0, $this->calculator->costToReach(100, 0));
        self::assertSame(0, $this->calculator->costBetween(100, 3, 3));
    }

    /**
     * A lower target costs zero rather than refunding.
     *
     * There is no sell-back for facilities. A negative return here would be a credit the
     * endpoint then paid out, which is why the floor is in the arithmetic and not only in the
     * caller's validation.
     */
    public function testADowngradeIsFreeNotRefunded(): void
    {
        self::assertSame(0, $this->calculator->costBetween(100, 5, 2));
    }

    /** Negative levels are clamped, not trusted into the arithmetic. */
    public function testNegativeLevelsAreClamped(): void
    {
        self::assertSame(0, $this->calculator->costToReach(100, -3));
        self::assertSame(100, $this->calculator->costBetween(100, -5, 1));
    }

    public function testNextUpgradeCostIsZeroAtMaxLevel(): void
    {
        $template = $this->template(baseCost: 100, maxLevel: 3);

        self::assertSame(300, $this->calculator->nextUpgradeCost($template, 2));
        self::assertSame(0, $this->calculator->nextUpgradeCost($template, 3));
        self::assertSame(0, $this->calculator->nextUpgradeCost($template, 4));
    }

    /**
     * Upkeep is `base x 1.5^level`, so level 1 is 1.5x the stored base.
     *
     * THIS ASSERTION WAS THE OTHER WAY ROUND UNTIL 2026-11-12, and the flip is the point: the
     * column carried two contradictory readings ("cost at level 1" and `1.5^level`) and the
     * server had picked the one that made its figure 1.5x cheaper than what the app charges.
     * The app is the game engine and therefore the tie-breaker. Levels 0-5 are pinned here so
     * the alignment cannot drift back by one power unnoticed.
     */
    public function testUpkeepCompoundsFromLevelOne(): void
    {
        $template = $this->template(baseCost: 100, maxLevel: 5, upkeep: 1000);

        self::assertSame(0, $this->calculator->weeklyUpkeep($template, 0), 'an unbuilt facility costs nothing to run');
        self::assertSame(1500, $this->calculator->weeklyUpkeep($template, 1));
        self::assertSame(2250, $this->calculator->weeklyUpkeep($template, 2));
        self::assertSame(3375, $this->calculator->weeklyUpkeep($template, 3));
        self::assertSame(5063, $this->calculator->weeklyUpkeep($template, 4));
        self::assertSame(7594, $this->calculator->weeklyUpkeep($template, 5));
    }

    public function testFacilityWithNoUpkeepCostsNothingToRun(): void
    {
        $template = $this->template(baseCost: 100, maxLevel: 5, upkeep: 0);

        self::assertSame(0, $this->calculator->weeklyUpkeep($template, 5));
    }

    private function template(int $baseCost, int $maxLevel, int $upkeep = 0): FacilityTemplate
    {
        $template = new FacilityTemplate();
        $template->setBaseCost($baseCost);
        $template->setMaxLevel($maxLevel);
        $template->setWeeklyUpkeepBase($upkeep);

        return $template;
    }
}
