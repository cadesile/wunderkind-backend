<?php

namespace App\Tests\Entity;

use App\Entity\NpcClub;
use App\Enum\CitySize;
use PHPUnit\Framework\TestCase;

class NpcClubCitySizeFieldsTest extends TestCase
{
    public function testDefaultsWhenNotProvided(): void
    {
        $club = new NpcClub('Test FC', 'EN', 8, 10, '#fff', '#000', 50000, []);

        $this->assertNull($club->getRegion());
        $this->assertSame(CitySize::MEDIUM, $club->getCitySize());
        $this->assertSame(0, $club->getPopulationSize());
        $this->assertFalse($club->isCapital());
    }

    public function testConstructorAcceptsCitySizeFields(): void
    {
        $club = new NpcClub(
            'London FC', 'EN', 1, 90, '#fff', '#000', 100_000_000, [],
            region: 'Greater London',
            citySize: CitySize::BIG,
            populationSize: 8_982_000,
            isCapital: true,
        );

        $this->assertSame('Greater London', $club->getRegion());
        $this->assertSame(CitySize::BIG, $club->getCitySize());
        $this->assertSame(8_982_000, $club->getPopulationSize());
        $this->assertTrue($club->isCapital());
    }

    public function testSetters(): void
    {
        $club = new NpcClub('Test FC', 'EN', 8, 10, '#fff', '#000', 50000, []);

        $club->setRegion('North West');
        $club->setCitySize(CitySize::SMALL);
        $club->setPopulationSize(35000);
        $club->setIsCapital(false);

        $this->assertSame('North West', $club->getRegion());
        $this->assertSame(CitySize::SMALL, $club->getCitySize());
        $this->assertSame(35000, $club->getPopulationSize());
        $this->assertFalse($club->isCapital());
    }
}
