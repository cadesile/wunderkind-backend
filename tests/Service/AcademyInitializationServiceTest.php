<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Academy;
use App\Entity\User;
use App\Service\AcademyInitializationService;
use App\Service\FacilityService;
use App\Service\MarketPoolService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AcademyInitializationServiceTest extends TestCase
{
    private MarketPoolService&MockObject $pool;
    private FacilityService&MockObject $facilityService;
    private EntityManagerInterface&MockObject $em;
    private AcademyInitializationService $service;

    protected function setUp(): void
    {
        $this->pool            = $this->createMock(MarketPoolService::class);
        $this->facilityService = $this->createMock(FacilityService::class);
        $this->em              = $this->createMock(EntityManagerInterface::class);

        $this->service = new AcademyInitializationService(
            $this->pool,
            $this->facilityService,
            $this->em,
        );
    }

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
