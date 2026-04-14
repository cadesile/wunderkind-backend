<?php

namespace App\Tests\Service;

use App\Entity\Academy;
use App\Entity\League;
use App\Entity\NpcClub;
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
        $service = $this->createStub(\App\Service\SyncService::class);

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

        $service = $this->createStub(\App\Service\SyncService::class);

        // Inject the npcClubRepository via reflection since constructor is disabled
        $repoProp = new \ReflectionProperty(\App\Service\SyncService::class, 'npcClubRepository');
        $repoProp->setValue($service, $npcRepo);

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
}
