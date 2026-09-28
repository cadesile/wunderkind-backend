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
use App\Service\ScoutGenerationService;
use App\Service\StaffGenerationService;
use App\Service\WorldPackSnapshotBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class MarketPoolAssignTest extends TestCase
{
    private function makeService(EntityManagerInterface $em, WorldPackSnapshotBuilder $snapshotBuilder): MarketPoolService
    {
        return new MarketPoolService(
            $em,
            $this->createStub(PlayerRepository::class),
            $this->createStub(StaffRepository::class),
            $this->createStub(ScoutRepository::class),
            $this->createStub(AgentRepository::class),
            $this->createStub(SponsorRepository::class),
            $this->createStub(InvestorRepository::class),
            $this->createStub(NameGeneratorService::class),
            $this->createStub(PoolConfigRepository::class),
            $this->createStub(GameConfigRepository::class),
            $this->createStub(PlayerGenerationService::class),
            $snapshotBuilder,
            $this->createStub(StaffGenerationService::class),
            $this->createStub(ScoutGenerationService::class),
        );
    }

    public function testPlayerAssignDeletesEntityAndReturnsSnapshot(): void
    {
        $player   = $this->createStub(Player::class);
        $club     = $this->createStub(Club::class);
        $snapshot = ['id' => 'uuid-player', 'firstName' => 'Test'];

        $snapshotBuilder = $this->createMock(WorldPackSnapshotBuilder::class);
        $snapshotBuilder->expects($this->once())
            ->method('buildPlayerSnapshot')
            ->with($player)
            ->willReturn($snapshot);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($player);
        $em->expects($this->once())->method('flush');

        $service = $this->makeService($em, $snapshotBuilder);
        $result  = $service->assignToClub($player, $club);

        $this->assertSame($snapshot, $result);
    }

    public function testStaffAssignDeletesEntityAndReturnsSnapshot(): void
    {
        $staff    = $this->createStub(Staff::class);
        $club     = $this->createStub(Club::class);
        $snapshot = ['id' => 'uuid-staff', 'role' => 'coach'];

        $snapshotBuilder = $this->createMock(WorldPackSnapshotBuilder::class);
        $snapshotBuilder->expects($this->once())
            ->method('buildStaffSnapshot')
            ->with($staff)
            ->willReturn($snapshot);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($staff);
        $em->expects($this->once())->method('flush');

        $service = $this->makeService($em, $snapshotBuilder);
        $result  = $service->assignToClub($staff, $club);

        $this->assertSame($snapshot, $result);
    }

    public function testScoutAssignIsNoOpAndReturnsNull(): void
    {
        $scout = $this->createStub(Scout::class);
        $club  = $this->createStub(Club::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('remove');
        $em->expects($this->never())->method('flush');

        $service = $this->makeService($em, $this->createStub(WorldPackSnapshotBuilder::class));
        $result  = $service->assignToClub($scout, $club);

        $this->assertNull($result);
    }

    public function testSponsorAssignSetsClubAndReturnsNull(): void
    {
        $sponsor = $this->createMock(Sponsor::class);
        $sponsor->method('isInMarketPool')->willReturn(true);
        $sponsor->expects($this->once())->method('setClub');
        $sponsor->expects($this->once())->method('setAssignedAt');

        $club = $this->createStub(Club::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = $this->makeService($em, $this->createStub(WorldPackSnapshotBuilder::class));
        $result  = $service->assignToClub($sponsor, $club);

        $this->assertNull($result);
    }
}
