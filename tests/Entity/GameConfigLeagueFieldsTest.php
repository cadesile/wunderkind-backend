<?php

namespace App\Tests\Entity;

use App\Entity\GameConfig;
use PHPUnit\Framework\TestCase;

class GameConfigLeagueFieldsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = new GameConfig();

        $this->assertSame(0,  $config->getSmallSponsorMin());
        $this->assertSame(0,  $config->getSmallSponsorMax());
        $this->assertSame(0,  $config->getMediumSponsorMin());
        $this->assertSame(0,  $config->getMediumSponsorMax());
        $this->assertSame(0,  $config->getLargeSponsorMin());
        $this->assertSame(0,  $config->getLargeSponsorMax());
        $this->assertSame(5,  $config->getLeaguePositionDecreasePercent());
    }

    public function testSetters(): void
    {
        $config = new GameConfig();
        $config->setSmallSponsorMin(10000);
        $config->setSmallSponsorMax(50000);
        $config->setMediumSponsorMin(50000);
        $config->setMediumSponsorMax(200000);
        $config->setLargeSponsorMin(200000);
        $config->setLargeSponsorMax(1000000);
        $config->setLeaguePositionDecreasePercent(10);

        $this->assertSame(10000,   $config->getSmallSponsorMin());
        $this->assertSame(50000,   $config->getSmallSponsorMax());
        $this->assertSame(50000,   $config->getMediumSponsorMin());
        $this->assertSame(200000,  $config->getMediumSponsorMax());
        $this->assertSame(200000,  $config->getLargeSponsorMin());
        $this->assertSame(1000000, $config->getLargeSponsorMax());
        $this->assertSame(10,      $config->getLeaguePositionDecreasePercent());
    }
}
