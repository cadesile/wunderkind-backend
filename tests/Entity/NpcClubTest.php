<?php

namespace App\Tests\Entity;

use App\Entity\NpcClub;
use PHPUnit\Framework\TestCase;

class NpcClubTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $club = new NpcClub(
            name: 'Sevilla FC',
            country: 'ES',
            tier: 2,
            reputation: 75,
            primaryColor: '#c0392b',
            secondaryColor: '#ffffff',
            balance: 5000000,
            facilities: ['training_pitch' => 7, 'north_stand' => 4],
        );

        $this->assertSame('Sevilla FC', $club->getName());
        $this->assertSame('ES', $club->getCountry());
        $this->assertSame(2, $club->getTier());
        $this->assertSame(75, $club->getReputation());
        $this->assertSame('#c0392b', $club->getPrimaryColor());
        $this->assertSame('#ffffff', $club->getSecondaryColor());
        $this->assertSame(5000000, $club->getBalance());
        $this->assertSame(['training_pitch' => 7, 'north_stand' => 4], $club->getFacilities());
        $this->assertNull($club->getStadiumName());
        $this->assertNotNull($club->getId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $club->getCreatedAt());
    }

    public function testStadiumNameSetter(): void
    {
        $club = new NpcClub('Test FC', 'EN', 1, 80, '#000', '#fff', 1000000, []);
        $club->setStadiumName('The Test Arena');
        $this->assertSame('The Test Arena', $club->getStadiumName());
    }
}
