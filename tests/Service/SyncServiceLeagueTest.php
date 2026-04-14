<?php

namespace App\Tests\Service;

use App\Entity\Academy;
use App\Entity\GameConfig;
use App\Entity\League;
use App\Entity\NpcClub;
use App\Entity\Sponsor;
use App\Entity\User;
use App\Repository\NpcClubRepository;
use PHPUnit\Framework\TestCase;

class SyncServiceLeagueTest extends TestCase
{
    public function testBuildLeagueSnapshotReturnsNullWhenNoLeague(): void
    {
        $user    = $this->createStub(User::class);
        $academy = new Academy('Test FC', $user);
        // no league set — currentLeague is null

        // Call the private method via reflection
        $service = $this->getMockBuilder(\App\Service\SyncService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $reflection = new \ReflectionMethod(\App\Service\SyncService::class, 'buildLeagueSnapshot');
        $result = $reflection->invoke($service, $academy);

        $this->assertNull($result);
    }

    public function testBuildLeagueSnapshotIncludesClubs(): void
    {
        $user    = $this->createStub(User::class);
        $academy = new Academy('Test FC', $user);
        $league  = new League('EN', 8, 'League 8');
        $academy->setCurrentLeague($league);
        $academy->setCurrentSeason(2);

        $club = new NpcClub('Norwich Town', 'EN', 8, 12, '#ffffff', '#000000', 100000, ['training_pitch' => 1]);
        $club->setStadiumName('Norwich Park');

        $npcRepo = $this->createStub(NpcClubRepository::class);
        $npcRepo->method('findByLeague')->willReturn([$club]);

        $gameConfig = new GameConfig();
        $configRepo = $this->createStub(\App\Repository\GameConfigRepository::class);
        $configRepo->method('getConfig')->willReturn($gameConfig);

        $service = $this->getMockBuilder(\App\Service\SyncService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        // Inject the npcClubRepository via reflection since constructor is disabled
        $repoProp = new \ReflectionProperty(\App\Service\SyncService::class, 'npcClubRepository');
        $repoProp->setValue($service, $npcRepo);

        $configProp = new \ReflectionProperty(\App\Service\SyncService::class, 'gameConfigRepository');
        $configProp->setValue($service, $configRepo);

        $reflection = new \ReflectionMethod(\App\Service\SyncService::class, 'buildLeagueSnapshot');
        $result = $reflection->invoke($service, $academy);

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
        $academy = new Academy('Test FC', $user);
        $league  = new League('EN', 5, 'League 5');
        $league->setPromotionSpots(3);
        $league->setTvDeal(500000);
        $league->setLeagueReputationTier(\App\Enum\ReputationTier::REGIONAL);
        $league->setPrizeMoney(200000);
        $league->setLeaguePositionPot(300000);

        $sponsor = new \App\Entity\Sponsor('Corp A');
        $sponsor->setSize(\App\Enum\CompanySize::SMALL);
        $league->addSponsor($sponsor);
        $league->getLeagueSponsors()->first()->setRolledValue(75000);

        $academy->setCurrentLeague($league);
        $academy->setCurrentSeason(3);

        $npcRepo = $this->createStub(NpcClubRepository::class);
        $npcRepo->method('findByLeague')->willReturn([]);

        $gameConfig = new \App\Entity\GameConfig();
        $gameConfig->setLeaguePositionDecreasePercent(8);
        $configRepo = $this->createStub(\App\Repository\GameConfigRepository::class);
        $configRepo->method('getConfig')->willReturn($gameConfig);

        $service = $this->getMockBuilder(\App\Service\SyncService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $repoProp = new \ReflectionProperty(\App\Service\SyncService::class, 'npcClubRepository');
        $repoProp->setValue($service, $npcRepo);

        $configProp = new \ReflectionProperty(\App\Service\SyncService::class, 'gameConfigRepository');
        $configProp->setValue($service, $configRepo);

        $reflection = new \ReflectionMethod(\App\Service\SyncService::class, 'buildLeagueSnapshot');
        $result     = $reflection->invoke($service, $academy);

        $this->assertNotNull($result);
        $this->assertSame(3,          $result['promotionSpots']);
        $this->assertSame(500000,     $result['tvDeal']);
        $this->assertSame('regional', $result['reputationTier']);
        $this->assertSame(200000,     $result['prizeMoney']);
        $this->assertSame(300000,     $result['leaguePositionPot']);
        $this->assertSame(75000,      $result['sponsorPot']);
        $this->assertSame(8,          $result['leaguePositionDecreasePercent']);
    }
}
