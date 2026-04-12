<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Academy;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class AcademyInitializationServiceTest extends TestCase
{
    public function testNewAcademyReceivesPaName(): void
    {
        $user    = $this->createMock(User::class);
        $academy = new Academy('Test Academy', $user);

        // PA name should be null until explicitly set
        $this->assertNull($academy->getPaName());

        $academy->setPaName('Marcus Richards');
        $this->assertSame('Marcus Richards', $academy->getPaName());
    }

    public function testManagerTraitsAreClampedAtBoundaries(): void
    {
        $user    = $this->createMock(User::class);
        $academy = new Academy('Test Academy', $user);

        $academy->setManagerTemperament(150);
        $this->assertSame(100, $academy->getManagerTemperament());

        $academy->setManagerDiscipline(-10);
        $this->assertSame(0, $academy->getManagerDiscipline());

        $academy->setManagerAmbition(50);
        $this->assertSame(50, $academy->getManagerAmbition());
    }

    public function testManagerTraitsDefaultToFifty(): void
    {
        $user    = $this->createMock(User::class);
        $academy = new Academy('Test Academy', $user);

        $this->assertSame(50, $academy->getManagerTemperament());
        $this->assertSame(50, $academy->getManagerDiscipline());
        $this->assertSame(50, $academy->getManagerAmbition());
    }
}
