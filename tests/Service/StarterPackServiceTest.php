<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Entity\StarterConfig;
use App\Enum\PlayerPosition;
use App\Exception\InsufficientStarterPoolException;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Repository\StarterConfigRepository;
use App\Service\StarterPackService;
use App\Service\WorldPackSnapshotBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class StarterPackServiceTest extends TestCase
{
    public function testInitializeDeletesConsumedPlayersAndStaff(): void
    {
        $config = $this->createStub(StarterConfig::class);
        $config->method('getStarterPlayerCount')->willReturn(2);
        $config->method('getStarterCoachCount')->willReturn(1);
        $config->method('getStarterManagerCount')->willReturn(0);
        $config->method('getStarterChairmanCount')->willReturn(0);
        $config->method('getStarterDirectorOfFootballCount')->willReturn(0);
        $config->method('getStarterFacilityManagerCount')->willReturn(0);
        $config->method('getStarterScoutCount')->willReturn(1);
        $config->method('getLeagueAbilityRanges')->willReturn([]);

        $starterConfigRepo = $this->createStub(StarterConfigRepository::class);
        $starterConfigRepo->method('getConfig')->willReturn($config);

        $club = $this->createStub(Club::class);
        $club->method('getCountry')->willReturn('EN');
        $club->method('getCurrentLeague')->willReturn(null);
        $club->method('isStarterInitialized')->willReturn(false);

        // Two mock Player entities in the pool
        $player1 = $this->createStub(Player::class);
        $player2 = $this->createStub(Player::class);

        $playerRepo = $this->createStub(PlayerRepository::class);
        $playerCallCount = 0;
        $playerRepo->method('findForWorldInitByPositionAndNationality')
            ->willReturnCallback(function () use (&$playerCallCount, $player1, $player2) {
                $playerCallCount++;
                return $playerCallCount === 1 ? [$player1] : [$player2];
            });
        $playerRepo->method('findForeignForWorldInitByPosition')->willReturn([]);

        $staff1 = $this->createStub(Staff::class);
        $staffRepo = $this->createStub(StaffRepository::class);
        $staffRepo->method('findInPoolByRoleRandom')->willReturn([$staff1]);

        $scout1 = $this->createStub(Scout::class);
        $scoutRepo = $this->createStub(ScoutRepository::class);
        $scoutRepo->method('findInPool')->willReturn([$scout1]);

        $poolConfig = $this->createStub(\App\Entity\PoolConfig::class);
        $poolConfig->method('getPositionWeightGk')->willReturn(1);
        $poolConfig->method('getPositionWeightDef')->willReturn(1);
        $poolConfig->method('getPositionWeightMid')->willReturn(0);
        $poolConfig->method('getPositionWeightAtt')->willReturn(0);
        $poolConfigRepo = $this->createStub(PoolConfigRepository::class);
        $poolConfigRepo->method('getConfig')->willReturn($poolConfig);

        $snapshotBuilder = $this->createStub(WorldPackSnapshotBuilder::class);
        $snapshotBuilder->method('distributeByPosition')->willReturn([
            'GK'  => 1,
            'DEF' => 1,
        ]);
        $snapshotBuilder->method('buildPlayerSnapshot')->willReturn(['id' => 'player-uuid']);
        $snapshotBuilder->method('buildStaffSnapshot')->willReturn(['id' => 'staff-uuid']);
        $snapshotBuilder->method('buildScoutSnapshot')->willReturn(['id' => 'scout-uuid']);

        // Key assertion: em->remove() must be called for each consumed Player and Staff
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(3)) // 2 players + 1 staff
            ->method('remove')
            ->with($this->logicalOr(
                $this->identicalTo($player1),
                $this->identicalTo($player2),
                $this->identicalTo($staff1),
            ));
        $em->expects($this->atLeastOnce())->method('flush');

        $service = new StarterPackService(
            $playerRepo,
            $staffRepo,
            $scoutRepo,
            $starterConfigRepo,
            $poolConfigRepo,
            $snapshotBuilder,
            $em,
        );

        $result = $service->initialize($club);
        $this->assertArrayHasKey('players', $result);
        $this->assertArrayHasKey('staff', $result);
        $this->assertArrayHasKey('scouts', $result);
    }

    /**
     * Regression test: a club whose nationality (and the foreign fallback) has zero players
     * in the pool used to still get Club::$starterInitializedAt set and the pool entities it
     * did find consumed, permanently stranding the club with an empty squad and no way to
     * retry once the pool was warmed. Must now throw before touching the pool or the club at
     * all.
     */
    public function testInitializeThrowsAndTouchesNothingWhenPlayerPoolIsEmpty(): void
    {
        $config = $this->createStub(StarterConfig::class);
        $config->method('getStarterPlayerCount')->willReturn(2);
        $config->method('getLeagueAbilityRanges')->willReturn([]);

        $starterConfigRepo = $this->createStub(StarterConfigRepository::class);
        $starterConfigRepo->method('getConfig')->willReturn($config);

        $club = $this->createStub(Club::class);
        $club->method('getCountry')->willReturn('US');
        $club->method('getCurrentLeague')->willReturn(null);

        $playerRepo = $this->createStub(PlayerRepository::class);
        $playerRepo->method('findForWorldInitByPositionAndNationality')->willReturn([]);
        $playerRepo->method('findForeignForWorldInitByPosition')->willReturn([]);

        $staffRepo = $this->createMock(StaffRepository::class);
        $staffRepo->expects($this->never())->method('findInPoolByRoleRandom');

        $scoutRepo = $this->createMock(ScoutRepository::class);
        $scoutRepo->expects($this->never())->method('findInPool');

        $poolConfig = $this->createStub(\App\Entity\PoolConfig::class);
        $poolConfigRepo = $this->createStub(PoolConfigRepository::class);
        $poolConfigRepo->method('getConfig')->willReturn($poolConfig);

        $snapshotBuilder = $this->createStub(WorldPackSnapshotBuilder::class);
        $snapshotBuilder->method('distributeByPosition')->willReturn(['GK' => 1, 'DEF' => 1]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('remove');
        $em->expects($this->never())->method('flush');

        $service = new StarterPackService(
            $playerRepo,
            $staffRepo,
            $scoutRepo,
            $starterConfigRepo,
            $poolConfigRepo,
            $snapshotBuilder,
            $em,
        );

        $this->expectException(InsufficientStarterPoolException::class);
        $service->initialize($club);
    }
}
