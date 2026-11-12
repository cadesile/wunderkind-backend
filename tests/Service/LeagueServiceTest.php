<?php

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\League;
use App\Entity\NpcClub;
use App\Entity\User;
use App\Repository\LeagueRepository;
use App\Service\LeagueService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class LeagueServiceTest extends TestCase
{
    private function makeService(LeagueRepository $repo): LeagueService
    {
        $em         = $this->createStub(EntityManagerInterface::class);
        $configRepo = $this->createStub(\App\Repository\GameConfigRepository::class);
        $configRepo->method('getConfig')->willReturn(new \App\Entity\GameConfig());
        $fixtureGenerationService = $this->createStub(\App\Service\FixtureGenerationService::class);
        $hallOfFameScoreService = $this->createStub(\App\Service\HallOfFameScoreService::class);
        $leaderboardCalculationService = $this->createStub(\App\Service\LeaderboardCalculationService::class);

        return new LeagueService(
            $repo,
            $em,
            $configRepo,
            $fixtureGenerationService,
            $hallOfFameScoreService,
            $leaderboardCalculationService,
            $this->createStub(\App\Repository\SeasonRecordRepository::class),
            $this->createStub(\App\Repository\SeasonSnapshotRepository::class),
            // A REAL resolver over stubbed repositories, because it is final and cannot be
            // doubled. With stubs it reports 0 NPC clubs, so no league here has a resolvable
            // size — which is correct for fixtures that author no pool and therefore derive
            // no per-club share.
            new \App\Service\Economy\LeagueSizeResolver(
                $this->createStub(\App\Repository\NpcClubRepository::class),
                $repo,
            ),
        );
    }

    public function testGenerateLeaguesStillDefaultsToEightTiers(): void
    {
        // The guard on the default. Every existing caller omits the tier count, so if the
        // default ever moved they would all quietly build a different pyramid.
        $repo = $this->createStub(LeagueRepository::class);
        // tier 1 already exists, tiers 2–8 do not
        $repo->method('findByCountryAndTier')
             ->willReturnCallback(fn($c, $t) => $t === 1 ? new League('EN', 1, 'League 1') : null);
        $repo->method('findByCountry')->willReturn([]);

        $service = $this->makeService($repo);
        $result  = $service->generateLeaguesForCountry('EN');

        $this->assertCount(7, $result['created']); // 8 tiers - 1 existing = 7
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(2, $result['created'][0]->getTier());
    }

    public function testGenerateLeaguesHonoursAShallowerPyramid(): void
    {
        $repo = $this->createStub(LeagueRepository::class);
        $repo->method('findByCountryAndTier')->willReturn(null);
        $repo->method('findByCountry')->willReturn([]);

        $service = $this->makeService($repo);
        $result  = $service->generateLeaguesForCountry('EN', 3);

        $this->assertCount(3, $result['created']);
        $this->assertSame([1, 2, 3], array_map(fn(League $l) => $l->getTier(), $result['created']));
    }

    public function testASingleTierCountryIsAllowed(): void
    {
        $repo = $this->createStub(LeagueRepository::class);
        $repo->method('findByCountryAndTier')->willReturn(null);
        $repo->method('findByCountry')->willReturn([]);

        $result = $this->makeService($repo)->generateLeaguesForCountry('EN', 1);

        $this->assertCount(1, $result['created']);
        $this->assertSame(1, $result['created'][0]->getTier());
    }

    public function testATierCountOutOfRangeIsClampedRatherThanTrusted(): void
    {
        $repo = $this->createStub(LeagueRepository::class);
        $repo->method('findByCountryAndTier')->willReturn(null);
        $repo->method('findByCountry')->willReturn([]);

        $service = $this->makeService($repo);

        $this->assertCount(8, $service->generateLeaguesForCountry('EN', 99)['created']);
        $this->assertCount(1, $service->generateLeaguesForCountry('EN', 0)['created']);
        $this->assertCount(1, $service->generateLeaguesForCountry('EN', -5)['created']);
    }

    public function testAssignClubToLeagueSetsLeague(): void
    {
        $league = new League('EN', 8, 'League 8');
        $repo   = $this->createStub(LeagueRepository::class);
        $repo->method('findByCountryAndTier')->willReturn($league);

        $club    = new NpcClub('Test FC', 'EN', 8, 10, '#fff', '#000', 50000, []);
        $service = $this->makeService($repo);
        $service->assignClubToLeague($club);

        $this->assertSame($league, $club->getLeague());
    }

    public function testAssignClubToLeagueDoesNothingIfNoLeague(): void
    {
        $repo = $this->createStub(LeagueRepository::class);
        $repo->method('findByCountryAndTier')->willReturn(null);

        $club    = new NpcClub('Test FC', 'EN', 8, 10, '#fff', '#000', 50000, []);
        $service = $this->makeService($repo);
        $service->assignClubToLeague($club);

        $this->assertNull($club->getLeague());
    }

    public function testAssignClubToStarterLeagueUsesTheLowestTierThatExists(): void
    {
        // Not tier 8. In a country with a shallower pyramid, looking up a hardcoded 8 finds
        // nothing and leaves the club with no league at all — silently.
        $bottom = new League('EN', 3, 'League 3');
        $repo   = $this->createStub(LeagueRepository::class);
        $repo->method('findLowestTierForCountry')->willReturn($bottom);
        $repo->method('findByCountryAndTier')->willReturn(null);

        $user    = $this->createStub(User::class);
        $club    = new Club('Test FC', $user);
        $service = $this->makeService($repo);
        $service->assignClubToStarterLeague($club, 'EN');

        $this->assertSame($bottom, $club->getCurrentLeague());
    }

    public function testAClubRelegatedFromTheBottomTierDoesNotMove(): void
    {
        // The old guard asked "is this tier below 8?". In a 3-tier country that let a
        // bottom-tier relegation go looking for tier 4. It happened to be harmless —
        // the lookup returned null, which reads as "no move" — but only by luck, and a
        // repository that answered differently would have moved the club out of the world.
        $bottom = new League('EN', 3, 'League 3');
        $repo   = $this->createMock(LeagueRepository::class);
        $repo->method('findLowestTierForCountry')->willReturn($bottom);
        // The point of the test: tier 4 is never asked for.
        $repo->expects($this->never())->method('findByCountryAndTier');

        $this->assertNull($this->resolveNewLeague($this->makeService($repo), $bottom, false, true));
    }

    public function testAClubRelegatedFromAboveTheBottomStillMovesDown(): void
    {
        $from   = new League('EN', 2, 'League 2');
        $bottom = new League('EN', 3, 'League 3');
        $repo   = $this->createStub(LeagueRepository::class);
        $repo->method('findLowestTierForCountry')->willReturn($bottom);
        $repo->method('findByCountryAndTier')
             ->willReturnCallback(fn($c, $t) => $t === 3 ? $bottom : null);

        $this->assertSame($bottom, $this->resolveNewLeague($this->makeService($repo), $from, false, true));
    }

    /** resolveNewLeague() is private; concludeSeason() is far too much machinery to reach it. */
    private function resolveNewLeague(LeagueService $service, League $from, bool $promoted, bool $relegated): ?League
    {
        $method = new \ReflectionMethod($service, 'resolveNewLeague');

        return $method->invoke($service, $from, $promoted, $relegated);
    }
}
