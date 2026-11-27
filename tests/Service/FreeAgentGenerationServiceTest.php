<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ClubInitializationService;
use App\Service\FreeAgentGenerationService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * World pack free agents (Courtney, 2026-11-27): the pack nationality only, weighted toward
 * the bottom half of the pyramid, no money.
 */
class FreeAgentGenerationServiceTest extends KernelTestCase
{
    public function testEightTierWeightsPutEightyPercentInTheBottomHalf(): void
    {
        $w = FreeAgentGenerationService::tierWeights([1, 2, 3, 4, 5, 6, 7, 8], 20);

        $this->assertEqualsWithDelta(1.0, array_sum($w), 1e-9);
        $this->assertEqualsWithDelta(0.80, $w[5] + $w[6] + $w[7] + $w[8], 1e-9);
        $this->assertEqualsWithDelta(0.02, $w[1], 1e-9);
        $this->assertEqualsWithDelta(0.08, $w[4], 1e-9);
        $this->assertGreaterThan($w[5], $w[8], 'deeper tiers weigh more');
        $this->assertGreaterThan($w[1], $w[4]);
    }

    public function testOddPyramidGivesTheBottomHalfTheExtraTier(): void
    {
        $w = FreeAgentGenerationService::tierWeights([1, 2, 3], 20);

        $this->assertEqualsWithDelta(0.20, $w[1], 1e-9);
        $this->assertEqualsWithDelta(0.80, $w[2] + $w[3], 1e-9);
    }

    public function testOneTierCountryGetsEverything(): void
    {
        $this->assertSame([1 => 1.0], FreeAgentGenerationService::tierWeights([1], 20));
    }

    public function testAllocationSumsToTheRequestedCount(): void
    {
        $w = FreeAgentGenerationService::tierWeights([1, 2, 3, 4, 5, 6, 7, 8], 20);

        foreach ([1, 7, 80, 200, 201] as $count) {
            $this->assertSame($count, array_sum(FreeAgentGenerationService::allocate($count, $w)));
        }
    }

    public function testGeneratedPlayersAreThePackNationalityAndCarryNoMoney(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(FreeAgentGenerationService::class);

        $players = $service->generate('EN', 24);

        $this->assertCount(24, $players);
        $nationality = ClubInitializationService::countryToNationality('EN');
        foreach ($players as $p) {
            $this->assertSame($nationality, $p['nationality']);
            $this->assertArrayNotHasKey('contractValue', $p);
        }
        $this->assertCount(24, array_unique(array_column($players, 'id')));
    }

    public function testBatchIsClampedToTheHardCeiling(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(FreeAgentGenerationService::class);

        $this->assertSame([], $service->generate('EN', 0));
    }
}
