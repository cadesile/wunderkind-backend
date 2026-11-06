<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GameConfig;
use PHPUnit\Framework\TestCase;

/**
 * A short pyramid is a whole world, not the top slice of a tall one.
 *
 * The per-tier config tables describe eight rungs. A country with three tiers has to be
 * stretched across those rungs rather than take the first three, or its bottom division
 * comes out as a comfortable second tier — rich clubs, big cities, respectable reputations
 * — when it is meant to be the grassroots end of that country's game.
 *
 * These pin the property that matters: whatever the depth, tier 1 is the top of the scale
 * and tier N is the bottom of it.
 */
class GameConfigTierScalingTest extends TestCase
{
    public function testTheBottomTierAlwaysMapsToTheBottomRung(): void
    {
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $depth) {
            $this->assertSame(
                $depth === 1 ? 1 : 8,
                GameConfig::scaleTierToEight($depth, $depth),
                "tier {$depth} of {$depth} should sit at the bottom of the scale",
            );
        }
    }

    public function testTheTopTierAlwaysMapsToTheTopRung(): void
    {
        foreach ([1, 3, 5, 8] as $depth) {
            $this->assertSame(1, GameConfig::scaleTierToEight(1, $depth));
        }
    }

    public function testASingleTierCountryDoesNotDivideByZero(): void
    {
        // The degenerate case. One division is that country's top flight, so it takes the
        // top of the scale rather than blowing up or landing at the bottom.
        $this->assertSame(1, GameConfig::scaleTierToEight(1, 1));
    }

    public function testBalanceForTheBottomTierMatchesTheFullPyramidFloor(): void
    {
        $config = new GameConfig();

        $this->assertSame(
            $config->getNpcClubBalanceRangeForTier(8, 8),
            $config->getNpcClubBalanceRangeForTier(3, 3),
            'the poorest division of a 3-tier country should be as poor as tier 8 of 8',
        );
    }

    public function testSizeWeightsForTheBottomTierMatchTheFullPyramidFloor(): void
    {
        $config = new GameConfig();

        $this->assertEquals(
            $config->getNpcClubSizeWeightsForTier(8, 8),
            $config->getNpcClubSizeWeightsForTier(3, 3),
            'the bottom of a 3-tier country should be as small-town as tier 8 of 8',
        );
    }

    public function testTheMiddleOfAShortPyramidSitsBetweenItsEnds(): void
    {
        $config = new GameConfig();

        $top    = $config->getNpcClubSizeWeightsForTier(1, 3)['small'];
        $middle = $config->getNpcClubSizeWeightsForTier(2, 3)['small'];
        $bottom = $config->getNpcClubSizeWeightsForTier(3, 3)['small'];

        $this->assertGreaterThan($top, $middle);
        $this->assertGreaterThan($middle, $bottom);
    }

    public function testTheDefaultStillDescribesAnEightTierWorld(): void
    {
        // Called without a depth — every pre-existing caller — nothing moves.
        $config = new GameConfig();

        foreach ([1, 4, 8] as $tier) {
            $this->assertSame(
                $config->getNpcClubBalanceRangeForTier($tier, 8),
                $config->getNpcClubBalanceRangeForTier($tier),
            );
            $this->assertEquals(
                $config->getNpcClubSizeWeightsForTier($tier, 8),
                $config->getNpcClubSizeWeightsForTier($tier),
            );
        }
    }
}
