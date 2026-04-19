<?php

namespace App\Tests\Entity;

use App\Entity\StarterConfig;
use App\Enum\ReputationTier;
use PHPUnit\Framework\TestCase;

class StarterConfigLeagueFieldsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = StarterConfig::defaults();

        $this->assertSame([],                    $config->getDefaultFacilities());
        $this->assertSame('{}',                  $config->getDefaultFacilitiesJson());
        $this->assertSame(ReputationTier::LOCAL,  $config->getStarterReputationTier());
    }

    public function testFacilitiesJsonRoundTrip(): void
    {
        $config = StarterConfig::defaults();
        $config->setDefaultFacilitiesJson('{"training_pitch": 2, "north_stand": 1}');

        $this->assertSame(['training_pitch' => 2, 'north_stand' => 1], $config->getDefaultFacilities());
        $this->assertStringContainsString('training_pitch', $config->getDefaultFacilitiesJson());
    }

    public function testReputationTierSetter(): void
    {
        $config = StarterConfig::defaults();
        $config->setStarterReputationTier(ReputationTier::NATIONAL);

        $this->assertSame(ReputationTier::NATIONAL, $config->getStarterReputationTier());
    }

    public function testLeagueAbilityRanges(): void
    {
        $config = StarterConfig::defaults();
        $this->assertSame([], $config->getLeagueAbilityRanges());

        $ranges = [
            'EN' => [
                '1' => ['min' => 75, 'max' => 100],
                '2' => ['min' => 70, 'max' => 95],
            ],
            'IT' => [
                '1' => ['min' => 80, 'max' => 100],
            ]
        ];
        $config->setLeagueAbilityRanges($ranges);

        $this->assertSame($ranges, $config->getLeagueAbilityRanges());
    }
}
