<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\Investor;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Sponsor;
use App\Entity\Staff;
use App\Repository\AgentRepository;
use App\Repository\GameConfigRepository;
use App\Repository\InvestorRepository;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\SponsorRepository;
use App\Repository\StaffRepository;
use App\Service\MarketPoolService;
use App\Service\NameGeneratorService;
use App\Service\PlayerGenerationService;
use App\Service\WorldInitializationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class MarketPoolAssignTest extends TestCase
{
    private function makeService(EntityManagerInterface $em, WorldInitializationService $worldInit): MarketPoolService
    {
        return new MarketPoolService(
            $em,
            $this->createMock(PlayerRepository::class),
            $this->createMock(StaffRepository::class),
            $this->createMock(ScoutRepository::class),
            $this->createMock(AgentRepository::class),
            $this->createMock(SponsorRepository::class),
            $this->createMock(InvestorRepository::class),
            $this->createMock(NameGeneratorService::class),
            $this->createMock(PoolConfigRepository::class),
            $this->createMock(GameConfigRepository::class),
            $this->createMock(PlayerGenerationService::class),
            $worldInit,
        );
    }

    public function testPlayerAssignDeletesEntityAndReturnsSnapshot(): void
    {
        $player   = $this->createMock(Player::class);
        $club     = $this->createMock(Club::class);
        $snapshot = ['id' => 'uuid-player', 'firstName' => 'Test'];

        $worldInit = $this->createMock(WorldInitializationService::class);
        $worldInit->expects($this->once())
            ->method('buildPlayerSnapshot')
            ->with($player)
            ->willReturn($snapshot);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($player);
        $em->expects($this->once())->method('flush');

        $service = $this->makeService($em, $worldInit);
        $result  = $service->assignToClub($player, $club);

        $this->assertSame($snapshot, $result);
    }

    public function testStaffAssignDeletesEntityAndReturnsSnapshot(): void
    {
        $staff    = $this->createMock(Staff::class);
        $club     = $this->createMock(Club::class);
        $snapshot = ['id' => 'uuid-staff', 'role' => 'coach'];

        $worldInit = $this->createMock(WorldInitializationService::class);
        $worldInit->expects($this->once())
            ->method('buildStaffSnapshot')
            ->with($staff)
            ->willReturn($snapshot);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($staff);
        $em->expects($this->once())->method('flush');

        $service = $this->makeService($em, $worldInit);
        $result  = $service->assignToClub($staff, $club);

        $this->assertSame($snapshot, $result);
    }

    public function testScoutAssignIsNoOpAndReturnsNull(): void
    {
        $scout = $this->createMock(Scout::class);
        $club  = $this->createMock(Club::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('remove');
        $em->expects($this->never())->method('flush');

        $service = $this->makeService($em, $this->createMock(WorldInitializationService::class));
        $result  = $service->assignToClub($scout, $club);

        $this->assertNull($result);
    }

    public function testSponsorAssignSetsClubAndReturnsNull(): void
    {
        $sponsor = $this->createMock(Sponsor::class);
        $sponsor->method('isInMarketPool')->willReturn(true);
        $sponsor->expects($this->once())->method('setClub');
        $sponsor->expects($this->once())->method('setAssignedAt');

        $club = $this->createMock(Club::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = $this->makeService($em, $this->createMock(WorldInitializationService::class));
        $result  = $service->assignToClub($sponsor, $club);

        $this->assertNull($result);
    }
}
