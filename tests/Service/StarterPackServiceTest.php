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
    /**
     * Regression: starter packs were handing new clubs 11 and 12 year olds.
     *
     * The pool deliberately spans youth ages so it can also supply GET /api/players/youth,
     * and the squad draw filtered on ability, position and nationality with no age
     * condition at all — so it took whatever the ability band matched. Both pool queries
     * must now carry a date_of_birth cutoff derived from the configured first-team floor.
     *
     * Asserts the cutoff date itself, not merely that an argument was passed: an off-by-one
     * here is invisible in the response and only shows up as a 17 year old in a squad.
     */
    public function testPlayerDrawIsGatedOnTheFirstTeamAgeFloor(): void
    {
        $captured = [];

        // First position returns nothing so the foreign top-up also fires; the second
        // returns a player so the run completes — StarterPackService throws
        // InsufficientStarterPoolException when a draw yields no players at all, which is
        // itself the right behaviour for an under-age pool, just not what this test is for.
        $calls = 0;
        $playerRepo = $this->createStub(PlayerRepository::class);
        $playerRepo->method('findForWorldInitByPositionAndNationality')
            ->willReturnCallback(function (...$args) use (&$captured, &$calls) {
                $captured['native'][] = $args[6] ?? null;
                return ++$calls === 1 ? [] : [$this->createStub(Player::class)];
            });
        $playerRepo->method('findForeignForWorldInitByPosition')
            ->willReturnCallback(function (...$args) use (&$captured) {
                $captured['foreign'][] = $args[6] ?? null;
                return [];
            });

        $this->runInitialize($playerRepo, firstTeamAgeMin: 19);

        $expected = (new \DateTimeImmutable('today'))->modify('-19 years');

        $this->assertNotEmpty($captured['native'] ?? [], 'the native draw should have run');
        $this->assertNotEmpty($captured['foreign'] ?? [], 'the foreign top-up should have run');

        foreach (array_merge($captured['native'], $captured['foreign']) as $cutoff) {
            $this->assertInstanceOf(\DateTimeImmutable::class, $cutoff, 'every draw must carry an age cutoff');
            $this->assertSame(
                $expected->format('Y-m-d'),
                $cutoff->format('Y-m-d'),
                'cutoff must be today minus the configured first-team floor',
            );
        }
    }

    /** The floor is config, not a constant — changing it must move the cutoff. */
    public function testAgeFloorIsReadFromPoolConfig(): void
    {
        $captured = [];

        $playerRepo = $this->createStub(PlayerRepository::class);
        $playerRepo->method('findForWorldInitByPositionAndNationality')
            ->willReturnCallback(function (...$args) use (&$captured) {
                $captured[] = $args[6] ?? null;
                return [$this->createStub(Player::class)];
            });
        $playerRepo->method('findForeignForWorldInitByPosition')->willReturn([]);

        $this->runInitialize($playerRepo, firstTeamAgeMin: 24);

        $this->assertSame(
            (new \DateTimeImmutable('today'))->modify('-24 years')->format('Y-m-d'),
            $captured[0]->format('Y-m-d'),
        );
    }

    /** Minimal wiring for a starter-pack run; the draws themselves return nothing. */
    private function runInitialize(PlayerRepository $playerRepo, int $firstTeamAgeMin): void
    {
        $config = $this->createStub(StarterConfig::class);
        $config->method('getStarterPlayerCount')->willReturn(2);
        $config->method('getStarterCoachCount')->willReturn(0);
        $config->method('getStarterManagerCount')->willReturn(0);
        $config->method('getStarterChairmanCount')->willReturn(0);
        $config->method('getStarterDirectorOfFootballCount')->willReturn(0);
        $config->method('getStarterFacilityManagerCount')->willReturn(0);
        $config->method('getStarterScoutCount')->willReturn(0);
        $config->method('getLeagueAbilityRanges')->willReturn([]);

        $starterConfigRepo = $this->createStub(StarterConfigRepository::class);
        $starterConfigRepo->method('getConfig')->willReturn($config);

        $club = $this->createStub(Club::class);
        $club->method('getCountry')->willReturn('EN');
        $club->method('getCurrentLeague')->willReturn(null);
        $club->method('isStarterInitialized')->willReturn(false);

        $poolConfig = $this->createStub(\App\Entity\PoolConfig::class);
        $poolConfig->method('getPlayerFirstTeamAgeMin')->willReturn($firstTeamAgeMin);
        $poolConfigRepo = $this->createStub(PoolConfigRepository::class);
        $poolConfigRepo->method('getConfig')->willReturn($poolConfig);

        $snapshotBuilder = $this->createStub(WorldPackSnapshotBuilder::class);
        $snapshotBuilder->method('distributeByPosition')->willReturn(['GK' => 1, 'DEF' => 1]);

        $staffRepo = $this->createStub(StaffRepository::class);
        $staffRepo->method('findInPoolByRoleRandom')->willReturn([]);
        $scoutRepo = $this->createStub(ScoutRepository::class);
        $scoutRepo->method('findInPool')->willReturn([]);

        (new StarterPackService(
            $playerRepo,
            $staffRepo,
            $scoutRepo,
            $starterConfigRepo,
            $poolConfigRepo,
            $snapshotBuilder,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(\Psr\Log\LoggerInterface::class),
        ))->initialize($club);
    }

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

        // A mock, not a stub: the pack must be written onto the club in the same run that
        // consumes the pool. These snapshots are the only remaining copy of the squad — the
        // Player/Staff rows are deleted below — so a club left with
        // starterInitializedAt set and starterPackJson null can never be served its squad
        // again. See Club::$starterPackJson.
        $storedPack = null;
        $club = $this->createMock(Club::class);
        $club->method('getCountry')->willReturn('EN');
        $club->method('getCurrentLeague')->willReturn(null);
        $club->method('isStarterInitialized')->willReturn(false);
        $club->expects($this->once())
            ->method('setStarterPackJson')
            ->willReturnCallback(function (?array $pack) use (&$storedPack, $club) {
                $storedPack = $pack;

                return $club;
            });

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
            $this->createStub(\Psr\Log\LoggerInterface::class),
        );

        $result = $service->initialize($club);
        $this->assertArrayHasKey('players', $result);
        $this->assertArrayHasKey('staff', $result);
        $this->assertArrayHasKey('scouts', $result);

        $this->assertSame($result, $storedPack, 'the stored pack must be the body the client is handed');
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
            $this->createStub(\Psr\Log\LoggerInterface::class),
        );

        $this->expectException(InsufficientStarterPoolException::class);
        $service->initialize($club);
    }
}
