<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GameConfig;
use PHPUnit\Framework\TestCase;

/**
 * Where a short pyramid IS stretched across the eight-rung scale, and where it is not.
 *
 * THIS FILE USED TO ASSERT THE OPPOSITE OF HALF OF WHAT IT NOW ASSERTS, and the split is the
 * point. Every per-tier table was once stretched: a 3-tier country's bottom division drew the
 * tier-8-of-8 figures, so it came out as grassroots rather than as a comfortable second tier.
 * Good reasoning, and it does not survive the economy scaling chain — country factors, tier
 * factors and baseline turnover are all raw per-tier tables, and one link stretching while the
 * others do not gives a Scottish bottom-tier club a tier-8 budget and tier-4 wages.
 *
 * So the line is drawn by MECHANISM, not by preference:
 *
 *  - A ROW LOOKUP is indexed RAW. Tier t reads row t. `npcClubBalanceRanges` is the live case;
 *    the economy factor and turnover tables follow the same rule. There is no row 8 to hand a
 *    4-tier country, and inventing one is what the removed GameConfig::scaleTierToEight() did.
 *  - An INTERPOLATION still spans the country's OWN depth, because it has no rows to index and
 *    a fixed divisor of 7 would strand a 3-tier country 28% along its own scale. Size weights
 *    here, and NpcClubGenerationService::reputationForTier().
 *
 * Both halves are pinned below so the next person to find them disagreeing knows it was a
 * decision.
 */
class GameConfigTierScalingTest extends TestCase
{
    // ── Row lookups: raw ──────────────────────────────────────────────────

    public function testTheBalanceTableIsIndexedRawByTier(): void
    {
        $config = new GameConfig();
        $rows   = $config->getNpcClubBalanceRanges();

        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $tier) {
            $this->assertSame(
                $rows[$tier - 1],
                $config->getNpcClubBalanceRangeForTier($tier),
                "tier {$tier} must read row {$tier}",
            );
        }
    }

    /**
     * A 4-tier country's bottom division is NOT as poor as tier 8 of 8 any more.
     *
     * The inverse of what this file asserted before 2026-11-12. Stated as its own test because
     * it is the user-visible consequence of the change — Scottish clubs got richer — and
     * because an assertion that merely checks indexing would not say so.
     */
    public function testAShallowCountrysBottomTierNoLongerDrawsTheFullPyramidFloor(): void
    {
        $config = new GameConfig();

        $this->assertNotSame(
            $config->getNpcClubBalanceRangeForTier(8),
            $config->getNpcClubBalanceRangeForTier(4),
            'tier 4 must draw its own band, not the tier-8 one it used to be stretched onto',
        );
    }

    public function testAnOutOfRangeTierIsClampedRatherThanResolvingToNothing(): void
    {
        // A null row would surface downstream as a balance of zero rather than as an error,
        // so both ends clamp to a real band.
        $config = new GameConfig();
        $rows   = $config->getNpcClubBalanceRanges();

        $this->assertSame($rows[0], $config->getNpcClubBalanceRangeForTier(0));
        $this->assertSame($rows[0], $config->getNpcClubBalanceRangeForTier(-5));
        $this->assertSame($rows[7], $config->getNpcClubBalanceRangeForTier(99));
    }

    // ── Interpolations: still depth-aware ─────────────────────────────────

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

    public function testASingleTierCountryDoesNotDivideByZero(): void
    {
        // The degenerate case for the interpolation: one division is that country's top flight,
        // so it takes the top of the scale rather than blowing up.
        $config = new GameConfig();

        $this->assertEquals(
            $config->getNpcClubSizeWeightsForTier(1, 8),
            $config->getNpcClubSizeWeightsForTier(1, 1),
        );
    }

    public function testSizeWeightsCalledWithoutADepthStillDescribeAnEightTierWorld(): void
    {
        $config = new GameConfig();

        foreach ([1, 4, 8] as $tier) {
            $this->assertEquals(
                $config->getNpcClubSizeWeightsForTier($tier, 8),
                $config->getNpcClubSizeWeightsForTier($tier),
            );
        }
    }
}
