<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Entity\StarterConfig;
use App\Enum\PlayerPosition;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Repository\StarterConfigRepository;
use App\Service\StarterPackService;
use App\Service\WorldInitializationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class StarterPackServiceTest extends TestCase
{
    public function testInitializeDeletesConsumedPlayersAndStaff(): void
    {
        $config = $this->createMock(StarterConfig::class);
        $config->method('getStarterPlayerCount')->willReturn(2);
        $config->method('getStarterCoachCount')->willReturn(1);
        $config->method('getStarterManagerCount')->willReturn(0);
        $config->method('getStarterChairmanCount')->willReturn(0);
        $config->method('getStarterDirectorOfFootballCount')->willReturn(0);
        $config->method('getStarterFacilityManagerCount')->willReturn(0);
        $config->method('getStarterScoutCount')->willReturn(1);
        $config->method('getLeagueAbilityRanges')->willReturn([]);

        $starterConfigRepo = $this->createMock(StarterConfigRepository::class);
        $starterConfigRepo->method('getConfig')->willReturn($config);

        $club = $this->createMock(Club::class);
        $club->method('getCountry')->willReturn('EN');
        $club->method('getCurrentLeague')->willReturn(null);
        $club->method('isStarterInitialized')->willReturn(false);

        // Two mock Player entities in the pool
        $player1 = $this->createMock(Player::class);
        $player2 = $this->createMock(Player::class);

        $playerRepo = $this->createMock(PlayerRepository::class);
        $playerCallCount = 0;
        $playerRepo->method('findForWorldInitByPositionAndNationality')
            ->willReturnCallback(function () use (&$playerCallCount, $player1, $player2) {
                $playerCallCount++;
                return $playerCallCount === 1 ? [$player1] : [$player2];
            });
        $playerRepo->method('findForeignForWorldInitByPosition')->willReturn([]);

        $staff1 = $this->createMock(Staff::class);
        $staffRepo = $this->createMock(StaffRepository::class);
        $staffRepo->method('findInPoolByRoleRandom')->willReturn([$staff1]);

        $scout1 = $this->createMock(Scout::class);
        $scoutRepo = $this->createMock(ScoutRepository::class);
        $scoutRepo->method('findInPool')->willReturn([$scout1]);

        $poolConfig = $this->createMock(\App\Entity\PoolConfig::class);
        $poolConfig->method('getPositionWeightGk')->willReturn(1);
        $poolConfig->method('getPositionWeightDef')->willReturn(1);
        $poolConfig->method('getPositionWeightMid')->willReturn(0);
        $poolConfig->method('getPositionWeightAtt')->willReturn(0);
        $poolConfigRepo = $this->createMock(PoolConfigRepository::class);
        $poolConfigRepo->method('getConfig')->willReturn($poolConfig);

        $worldInit = $this->createMock(WorldInitializationService::class);
        $worldInit->method('distributeByPosition')->willReturn([
            'GK'  => 1,
            'DEF' => 1,
        ]);
        $worldInit->method('buildPlayerSnapshot')->willReturn(['id' => 'player-uuid']);
        $worldInit->method('buildStaffSnapshot')->willReturn(['id' => 'staff-uuid']);
        $worldInit->method('buildScoutSnapshot')->willReturn(['id' => 'scout-uuid']);

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
            $worldInit,
            $em,
        );

        $result = $service->initialize($club);
        $this->assertArrayHasKey('players', $result);
        $this->assertArrayHasKey('staff', $result);
        $this->assertArrayHasKey('scouts', $result);
    }
}
