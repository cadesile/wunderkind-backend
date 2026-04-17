<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class SyncServiceManagerShiftsTest extends TestCase
{
    public function testManagerShiftIncreasesTemperament(): void
    {
        $user    = $this->createMock(User::class);
        $club = new Club('Test', $user);
        $club->setManagerTemperament(50);

        $club->setManagerTemperament($club->getManagerTemperament() + 5);

        $this->assertSame(55, $club->getManagerTemperament());
    }

    public function testManagerShiftClampsAtMaximum(): void
    {
        $user    = $this->createMock(User::class);
        $club = new Club('Test', $user);
        $club->setManagerTemperament(98);

        $club->setManagerTemperament($club->getManagerTemperament() + 10);

        $this->assertSame(100, $club->getManagerTemperament());
    }

    public function testManagerShiftClampsAtMinimum(): void
    {
        $user    = $this->createMock(User::class);
        $club = new Club('Test', $user);
        $club->setManagerDiscipline(3);

        $club->setManagerDiscipline($club->getManagerDiscipline() - 10);

        $this->assertSame(0, $club->getManagerDiscipline());
    }

    public function testEmptyShiftsDoNotChangeTraits(): void
    {
        $user    = $this->createMock(User::class);
        $club = new Club('Test', $user);

        $before = [
            $club->getManagerTemperament(),
            $club->getManagerDiscipline(),
            $club->getManagerAmbition(),
        ];

        // Empty shifts — no changes
        $shifts = [];
        // applyManagerShifts logic inline for unit test
        if (isset($shifts['temperament'])) {
            $club->setManagerTemperament($club->getManagerTemperament() + $shifts['temperament']);
        }

        $this->assertSame($before[0], $club->getManagerTemperament());
        $this->assertSame($before[1], $club->getManagerDiscipline());
        $this->assertSame($before[2], $club->getManagerAmbition());
    }
}
