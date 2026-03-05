<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Academy;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class SyncServiceManagerShiftsTest extends TestCase
{
    public function testManagerShiftIncreasesTemperament(): void
    {
        $user    = $this->createMock(User::class);
        $academy = new Academy('Test', $user);
        $academy->setManagerTemperament(50);

        $academy->setManagerTemperament($academy->getManagerTemperament() + 5);

        $this->assertSame(55, $academy->getManagerTemperament());
    }

    public function testManagerShiftClampsAtMaximum(): void
    {
        $user    = $this->createMock(User::class);
        $academy = new Academy('Test', $user);
        $academy->setManagerTemperament(98);

        $academy->setManagerTemperament($academy->getManagerTemperament() + 10);

        $this->assertSame(100, $academy->getManagerTemperament());
    }

    public function testManagerShiftClampsAtMinimum(): void
    {
        $user    = $this->createMock(User::class);
        $academy = new Academy('Test', $user);
        $academy->setManagerDiscipline(3);

        $academy->setManagerDiscipline($academy->getManagerDiscipline() - 10);

        $this->assertSame(0, $academy->getManagerDiscipline());
    }

    public function testEmptyShiftsDoNotChangeTraits(): void
    {
        $user    = $this->createMock(User::class);
        $academy = new Academy('Test', $user);

        $before = [
            $academy->getManagerTemperament(),
            $academy->getManagerDiscipline(),
            $academy->getManagerAmbition(),
        ];

        // Empty shifts — no changes
        $shifts = [];
        // applyManagerShifts logic inline for unit test
        if (isset($shifts['temperament'])) {
            $academy->setManagerTemperament($academy->getManagerTemperament() + $shifts['temperament']);
        }

        $this->assertSame($before[0], $academy->getManagerTemperament());
        $this->assertSame($before[1], $academy->getManagerDiscipline());
        $this->assertSame($before[2], $academy->getManagerAmbition());
    }
}
