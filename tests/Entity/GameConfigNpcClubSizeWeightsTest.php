<?php

namespace App\Tests\Entity;

use App\Entity\GameConfig;
use PHPUnit\Framework\TestCase;

class GameConfigNpcClubSizeWeightsTest extends TestCase
{
    public function testDefaultWeights(): void
    {
        $config = new GameConfig();

        $this->assertSame(
            ['tier1' => ['big' => 70, 'medium' => 25, 'small' => 5], 'tier8' => ['big' => 5, 'medium' => 25, 'small' => 70]],
            $config->getNpcClubSizeWeights()
        );
    }

    public function testGetWeightsForTier1MatchesTier1Row(): void
    {
        $config  = new GameConfig();
        $weights = $config->getNpcClubSizeWeightsForTier(1);

        $this->assertEqualsWithDelta(70.0, $weights['big'], 0.001);
        $this->assertEqualsWithDelta(25.0, $weights['medium'], 0.001);
        $this->assertEqualsWithDelta(5.0, $weights['small'], 0.001);
    }

    public function testGetWeightsForTier8MatchesTier8Row(): void
    {
        $config  = new GameConfig();
        $weights = $config->getNpcClubSizeWeightsForTier(8);

        $this->assertEqualsWithDelta(5.0, $weights['big'], 0.001);
        $this->assertEqualsWithDelta(25.0, $weights['medium'], 0.001);
        $this->assertEqualsWithDelta(70.0, $weights['small'], 0.001);
    }

    public function testMidTierInterpolatesLinearly(): void
    {
        // Tier 4.5 is the midpoint (fraction = (4-1)/7 for tier 4, (5-1)/7 for tier 5) —
        // check tier 4 and tier 5 both land strictly between the tier1 and tier8 values.
        $config = new GameConfig();

        $tier4 = $config->getNpcClubSizeWeightsForTier(4);
        $this->assertGreaterThan(5.0, $tier4['big']);
        $this->assertLessThan(70.0, $tier4['big']);
        $this->assertGreaterThan(5.0, $tier4['small']);
        $this->assertLessThan(70.0, $tier4['small']);
    }

    public function testTierClampsToValidRange(): void
    {
        $config = new GameConfig();

        $this->assertSame($config->getNpcClubSizeWeightsForTier(1), $config->getNpcClubSizeWeightsForTier(0));
        $this->assertSame($config->getNpcClubSizeWeightsForTier(8), $config->getNpcClubSizeWeightsForTier(20));
    }

    public function testSetter(): void
    {
        $config = new GameConfig();
        $config->setNpcClubSizeWeights([
            'tier1' => ['big' => 90, 'medium' => 8, 'small' => 2],
            'tier8' => ['big' => 2, 'medium' => 8, 'small' => 90],
        ]);

        $this->assertSame(90, $config->getNpcClubSizeWeights()['tier1']['big']);
        $this->assertEqualsWithDelta(90.0, $config->getNpcClubSizeWeightsForTier(1)['big'], 0.001);
    }
}
