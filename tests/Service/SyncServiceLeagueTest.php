<?php

namespace App\Tests\Service;

use App\Entity\Club;
use App\Entity\GameConfig;
use App\Entity\League;
use App\Entity\NpcClub;
use App\Entity\Sponsor;
use App\Entity\User;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;
use PHPUnit\Framework\TestCase;

class SyncServiceLeagueTest extends TestCase
{
    public function testBuildLeagueSnapshotReturnsNullWhenNoLeague(): void
    {
        $user    = $this->createStub(User::class);
        $userClub = new Club('Test FC', $user);
        // no league set — currentLeague is null

        // Call the private method via reflection
        $service = $this->getStubBuilder(\App\Service\SyncService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getStub();

        $gameConfig = new GameConfig();

        $reflection = new \ReflectionMethod(\App\Service\SyncService::class, 'buildLeagueSnapshot');
        $result = $reflection->invoke($service, $userClub, $gameConfig);

        $this->assertNull($result);
    }

    public function testBuildLeagueSnapshotIncludesClubs(): void
    {
        $user    = $this->createStub(User::class);
        $userClub = new Club('Test FC', $user);
        $league  = new League('EN', 8, 'League 8');
        $userClub->setCurrentLeague($league);
        $userClub->setCurrentSeason(2);

        $npcClub = new NpcClub('Norwich Town', 'EN', 8, 12, '#ffffff', '#000000', 100000, ['training_pitch' => 1]);
        $npcClub->setStadiumName('Norwich Park');

        $npcRepo = $this->createStub(NpcClubRepository::class);
        $npcRepo->method('findByLeague')->willReturn([$npcClub]);

        $gameConfig = new GameConfig();

        $service = $this->getStubBuilder(\App\Service\SyncService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getStub();

        // Inject the npcClubRepository via reflection since constructor is disabled
        $repoProp = new \ReflectionProperty(\App\Service\SyncService::class, 'npcClubRepository');
        $repoProp->setValue($service, $npcRepo);
        $this->injectSizeResolver($service);

        $reflection = new \ReflectionMethod(\App\Service\SyncService::class, 'buildLeagueSnapshot');
        $result = $reflection->invoke($service, $userClub, $gameConfig);

        $this->assertNotNull($result);
        $this->assertSame(8,          $result['tier']);
        $this->assertSame('League 8', $result['name']);
        $this->assertSame('EN',       $result['country']);
        $this->assertSame(2,          $result['season']);
        $this->assertCount(1, $result['clubs']);
        $this->assertSame('Norwich Town', $result['clubs'][0]['name']);
    }

    public function testBuildLeagueSnapshotIncludesNewConfigFields(): void
    {
        $user    = $this->createStub(\App\Entity\User::class);
        $userClub = new Club('Test FC', $user);
        $league  = new League('EN', 5, 'League 5');
        $league->setPromotionSpots(3);
        $league->setTvDeal(500000);
        $league->setLeagueReputationTier(\App\Enum\ReputationTier::REGIONAL);
        $league->setPrizeMoney(200000);
        $league->setLeaguePositionPot(300000);

        $sponsor = new \App\Entity\Sponsor('Corp A');
        $sponsor->setSize(\App\Enum\CompanySize::SMALL);
        $leagueSponsor = new \App\Entity\LeagueSponsor($league, $sponsor);
        $leagueSponsor->setRolledValue(75000);
        $league->getLeagueSponsors()->add($leagueSponsor);

        $userClub->setCurrentLeague($league);
        $userClub->setCurrentSeason(3);

        $npcRepo = $this->createStub(NpcClubRepository::class);
        $npcRepo->method('findByLeague')->willReturn([]);

        $gameConfig = new \App\Entity\GameConfig();
        $gameConfig->setLeaguePositionDecreasePercent(8);

        $service = $this->getStubBuilder(\App\Service\SyncService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getStub();

        $repoProp = new \ReflectionProperty(\App\Service\SyncService::class, 'npcClubRepository');
        $repoProp->setValue($service, $npcRepo);
        $this->injectSizeResolver($service);

        $reflection = new \ReflectionMethod(\App\Service\SyncService::class, 'buildLeagueSnapshot');
        $result     = $reflection->invoke($service, $userClub, $gameConfig);

        $this->assertNotNull($result);
        $this->assertSame(3,          $result['promotionSpots']);
        $this->assertSame(500000,     $result['tvDeal']);
        $this->assertSame('regional', $result['reputationTier']);
        $this->assertSame(200000,     $result['prizeMoney']);
        $this->assertSame(300000,     $result['leaguePositionPot']);
        $this->assertSame(75000,      $result['sponsorPot']);
        // RESOLVED `league ?? gameConfig`, and this league sets no override, so the key still
        // carries the GameConfig value it always has. That is the half of the change that
        // matters for an old build: same key, same number.
        $this->assertSame(8,          $result['leaguePositionDecreasePercent']);

        // Additive. No pool authored on this fixture, so the pool is null and `tvDeal` above
        // is untouched — which is the state every league ships in.
        $this->assertNull($result['tvPoolPence']);
        self::assertArrayHasKey('clubCount', $result);
    }

    /** A per-league override must WIN over the GameConfig value, or the column is decorative. */
    public function testAPerLeagueTaperOverrideBeatsTheGlobalOne(): void
    {
        $user     = $this->createStub(User::class);
        $userClub = new Club('Test FC', $user);
        $league   = new League('EN', 8, 'League 8');
        $league->setLeaguePositionDecreasePercent(17);
        $userClub->setCurrentLeague($league);

        $gameConfig = new GameConfig();
        $gameConfig->setLeaguePositionDecreasePercent(5);

        $service = $this->getStubBuilder(\App\Service\SyncService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getStub();

        $repoProp = new \ReflectionProperty(\App\Service\SyncService::class, 'npcClubRepository');
        $repoProp->setValue($service, $this->createStub(NpcClubRepository::class));
        $this->injectSizeResolver($service);

        $result = (new \ReflectionMethod(\App\Service\SyncService::class, 'buildLeagueSnapshot'))
            ->invoke($service, $userClub, $gameConfig);

        self::assertSame(17, $result['leaguePositionDecreasePercent']);
    }

    /** A pooled league publishes the pool alongside the share released builds read. */
    public function testAPooledLeaguePublishesBothFigures(): void
    {
        $user     = $this->createStub(User::class);
        $userClub = new Club('Test FC', $user);
        $league   = new League('EN', 8, 'League 8');
        $league->setTvPoolPence(9_000_000);
        $league->setSlots(18);
        $league->setTvDeal(500_000);
        $userClub->setCurrentLeague($league);

        $service = $this->getStubBuilder(\App\Service\SyncService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getStub();

        $repoProp = new \ReflectionProperty(\App\Service\SyncService::class, 'npcClubRepository');
        $repoProp->setValue($service, $this->createStub(NpcClubRepository::class));
        $this->injectSizeResolver($service);

        $result = (new \ReflectionMethod(\App\Service\SyncService::class, 'buildLeagueSnapshot'))
            ->invoke($service, $userClub, new GameConfig());

        self::assertSame(9_000_000, $result['tvPoolPence']);
        self::assertSame(18, $result['clubCount'], 'authored slots win over a counted size');
        // The arithmetic a client can check: pool / clubCount === the published share.
        self::assertSame($result['tvDeal'], intdiv($result['tvPoolPence'], $result['clubCount']));
    }

    public function testAutoAssignSetsLowestTierLeagueWhenNoneAssigned(): void
    {
        $user    = $this->createStub(User::class);
        $userClub = new Club('Test FC', $user);
        $userClub->setCountry('EN');

        $league = new League('EN', 8, 'League 8');

        $leagueRepo = $this->createStub(LeagueRepository::class);
        $leagueRepo->method('findLowestTierForCountry')->willReturn($league);

        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($userClub);

        $service = $this->getStubBuilder(\App\Service\SyncService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getStub();

        $leagueRepoProp = new \ReflectionProperty(\App\Service\SyncService::class, 'leagueRepository');
        $leagueRepoProp->setValue($service, $leagueRepo);

        $emProp = new \ReflectionProperty(\App\Service\SyncService::class, 'em');
        $emProp->setValue($service, $em);

        $reflection = new \ReflectionMethod(\App\Service\SyncService::class, 'maybeAutoAssignLeague');
        $reflection->invoke($service, $userClub);

        $this->assertSame($league, $userClub->getCurrentLeague());
    }

    public function testAutoAssignIsNoOpWhenLeagueAlreadySet(): void
    {
        $user    = $this->createStub(User::class);
        $userClub = new Club('Test FC', $user);
        $existingLeague = new League('EN', 5, 'League 5');
        $userClub->setCurrentLeague($existingLeague);
        $userClub->setCountry('EN');

        $leagueRepo = $this->createMock(LeagueRepository::class);
        $leagueRepo->expects($this->never())->method('findLowestTierForCountry');

        $service = $this->getStubBuilder(\App\Service\SyncService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getStub();

        $leagueRepoProp = new \ReflectionProperty(\App\Service\SyncService::class, 'leagueRepository');
        $leagueRepoProp->setValue($service, $leagueRepo);

        $reflection = new \ReflectionMethod(\App\Service\SyncService::class, 'maybeAutoAssignLeague');
        $reflection->invoke($service, $userClub);

        $this->assertSame($existingLeague, $userClub->getCurrentLeague());
    }

    public function testAutoAssignIsNoOpWhenCountryIsNull(): void
    {
        $user    = $this->createStub(User::class);
        $userClub = new Club('Test FC', $user);

        $leagueRepo = $this->createMock(LeagueRepository::class);
        $leagueRepo->expects($this->never())->method('findLowestTierForCountry');

        $service = $this->getStubBuilder(\App\Service\SyncService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getStub();

        $leagueRepoProp = new \ReflectionProperty(\App\Service\SyncService::class, 'leagueRepository');
        $leagueRepoProp->setValue($service, $leagueRepo);

        $reflection = new \ReflectionMethod(\App\Service\SyncService::class, 'maybeAutoAssignLeague');
        $reflection->invoke($service, $userClub);

        $this->assertNull($userClub->getCurrentLeague());
    }

    public function testSyncResponseClubBlockIncludesId(): void
    {
        $user    = $this->createStub(User::class);
        $userClub = new Club('Test FC', $user);

        // Verify club has a UUID id
        $this->assertNotEmpty((string) $userClub->getId());

        // The id should be a valid UUID string format
        $id = (string) $userClub->getId();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
    }

    /**
     * `buildLeagueSnapshot()` now resolves a club count, so the size resolver has to be
     * present even though these tests construct SyncService with its constructor disabled.
     *
     * A REAL resolver over stubbed repositories rather than a double: it is final and cannot be
     * mocked, and with stubbed repositories it answers 0 NPC clubs — so `clubCount` comes out as
     * the bottom-tier slot alone, which is a correct answer for a league whose only member in
     * this fixture is the player's own club.
     */
    private function injectSizeResolver(object $service): void
    {
        $npcRepo    = $this->createStub(NpcClubRepository::class);
        $leagueRepo = $this->createStub(LeagueRepository::class);

        $prop = new \ReflectionProperty(\App\Service\SyncService::class, 'leagueSizeResolver');
        $prop->setValue($service, new \App\Service\Economy\LeagueSizeResolver($npcRepo, $leagueRepo));
    }
}
