<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\StarterConfig;
use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Repository\StarterConfigRepository;
use App\Service\MarketPoolService;
use App\Service\StarterPackService;
use App\Service\WorldInitializationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class StarterPackServicePrewarmTest extends TestCase
{
    public function testPrewarmGeneratesNationalityBufferBeforePoolQueries(): void
    {
        // StarterConfig: 5 players, 1 coach, 1 manager, 1 chairman, 0 DOF, 0 facility, 1 scout
        $config = $this->createMock(StarterConfig::class);
        $config->method('getStarterPlayerCount')->willReturn(5);
        $config->method('getStarterCoachCount')->willReturn(1);
        $config->method('getStarterManagerCount')->willReturn(1);
        $config->method('getStarterChairmanCount')->willReturn(1);
        $config->method('getStarterDirectorOfFootballCount')->willReturn(0);
        $config->method('getStarterFacilityManagerCount')->willReturn(0);
        $config->method('getStarterScoutCount')->willReturn(1);

        $starterConfigRepo = $this->createMock(StarterConfigRepository::class);
        $starterConfigRepo->method('getConfig')->willReturn($config);

        // Club is EN → nationality is 'English'
        $club = $this->createMock(Club::class);
        $club->method('getCountry')->willReturn('EN');
        $club->method('getCurrentLeague')->willReturn(null);
        $club->method('isStarterInitialized')->willReturn(false);
        $club->method('getReputation')->willReturn(0);

        $marketPool = $this->createMock(MarketPoolService::class);

        // Players: 5 * 2 = 10, nationality English
        $marketPool->expects($this->once())
            ->method('generatePlayers')
            ->with(10, RecruitmentSource::YOUTH_INTAKE, 'English');

        // Staff roles with count > 0: COACH, MANAGER, CHAIRMAN
        $marketPool->expects($this->exactly(3))
            ->method('generateStaffForRole')
            ->willReturnCallback(function (StaffRole $role, int $count, string $nat) {
                $this->assertSame('English', $nat);
                $this->assertGreaterThan(0, $count);
                $this->assertContains($role, [StaffRole::COACH, StaffRole::MANAGER, StaffRole::CHAIRMAN]);
                return [];
            });

        // Scouts: exact count 1
        $marketPool->expects($this->once())
            ->method('generateScouts')
            ->with(1, 'English');

        // Remaining dependencies — stub out so initialize() doesn't blow up
        $playerRepo = $this->createMock(PlayerRepository::class);
        $playerRepo->method('findForWorldInitByPositionAndNationality')->willReturn([]);
        $playerRepo->method('findForeignForWorldInitByPosition')->willReturn([]);

        $staffRepo = $this->createMock(StaffRepository::class);
        $staffRepo->method('findInPoolByRoleRandom')->willReturn([]);

        $scoutRepo = $this->createMock(ScoutRepository::class);
        $scoutRepo->method('findInPool')->willReturn([]);

        $poolConfigRepo = $this->createMock(PoolConfigRepository::class);
        $poolConfig = $this->createMock(\App\Entity\PoolConfig::class);
        $poolConfig->method('getPositionWeightGk')->willReturn(1);
        $poolConfig->method('getPositionWeightDef')->willReturn(4);
        $poolConfig->method('getPositionWeightMid')->willReturn(4);
        $poolConfig->method('getPositionWeightAtt')->willReturn(3);
        $poolConfigRepo->method('getConfig')->willReturn($poolConfig);

        $worldInit = $this->createMock(WorldInitializationService::class);
        $worldInit->method('distributeByPosition')->willReturn([
            'GK'  => 1,
            'DEF' => 2,
            'MID' => 1,
            'ATT' => 1,
        ]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush');

        $service = new StarterPackService(
            $playerRepo,
            $staffRepo,
            $scoutRepo,
            $starterConfigRepo,
            $poolConfigRepo,
            $worldInit,
            $em,
            $marketPool,
        );

        // initialize() returns the payload array; we only care that prewarm was called
        $result = $service->initialize($club);
        $this->assertArrayHasKey('players', $result);
        $this->assertArrayHasKey('staff', $result);
        $this->assertArrayHasKey('scouts', $result);
    }
}
