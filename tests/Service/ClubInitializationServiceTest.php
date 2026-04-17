<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class ClubInitializationServiceTest extends TestCase
{
    public function testNewClubReceivesPaName(): void
    {
        $user    = $this->createMock(User::class);
        $club = new Club('Test Club', $user);

        // PA name should be null until explicitly set
        $this->assertNull($club->getPaName());

        $club->setPaName('Marcus Richards');
        $this->assertSame('Marcus Richards', $club->getPaName());
    }

    public function testManagerTraitsAreClampedAtBoundaries(): void
    {
        $user    = $this->createMock(User::class);
        $club = new Club('Test Club', $user);

        $club->setManagerTemperament(150);
        $this->assertSame(100, $club->getManagerTemperament());

        $club->setManagerDiscipline(-10);
        $this->assertSame(0, $club->getManagerDiscipline());

        $club->setManagerAmbition(50);
        $this->assertSame(50, $club->getManagerAmbition());
    }

    public function testManagerTraitsDefaultToFifty(): void
    {
        $user    = $this->createMock(User::class);
        $club = new Club('Test Club', $user);

        $this->assertSame(50, $club->getManagerTemperament());
        $this->assertSame(50, $club->getManagerDiscipline());
        $this->assertSame(50, $club->getManagerAmbition());
    }
}
