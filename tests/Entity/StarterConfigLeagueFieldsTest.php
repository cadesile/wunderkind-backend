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
}
