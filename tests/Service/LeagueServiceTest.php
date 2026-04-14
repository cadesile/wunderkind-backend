<?php

namespace App\Tests\Service;

use App\Entity\Academy;
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
        $em = $this->createMock(EntityManagerInterface::class);
        return new LeagueService($repo, $em);
    }

    public function testGenerateLeaguesSkipsExisting(): void
    {
        $repo = $this->createMock(LeagueRepository::class);
        // tier 1 already exists, tiers 2–8 do not
        $repo->method('findByCountryAndTier')
             ->willReturnCallback(fn($c, $t) => $t === 1 ? new League('EN', 1, 'League 1') : null);

        $service = $this->makeService($repo);
        $created = $service->generateLeaguesForCountry('EN');

        $this->assertCount(7, $created); // 8 tiers - 1 existing = 7
        $this->assertSame(2, $created[0]->getTier());
    }

    public function testAssignClubToLeagueSetsLeague(): void
    {
        $league = new League('EN', 8, 'League 8');
        $repo   = $this->createMock(LeagueRepository::class);
        $repo->method('findByCountryAndTier')->willReturn($league);

        $club    = new NpcClub('Test FC', 'EN', 8, 10, '#fff', '#000', 50000, []);
        $service = $this->makeService($repo);
        $service->assignClubToLeague($club);

        $this->assertSame($league, $club->getLeague());
    }

    public function testAssignClubToLeagueDoesNothingIfNoLeague(): void
    {
        $repo = $this->createMock(LeagueRepository::class);
        $repo->method('findByCountryAndTier')->willReturn(null);

        $club    = new NpcClub('Test FC', 'EN', 8, 10, '#fff', '#000', 50000, []);
        $service = $this->makeService($repo);
        $service->assignClubToLeague($club);

        $this->assertNull($club->getLeague());
    }

    public function testAssignAcademyToStarterLeague(): void
    {
        $league = new League('EN', 8, 'League 8');
        $repo   = $this->createMock(LeagueRepository::class);
        $repo->method('findByCountryAndTier')->with('EN', 8)->willReturn($league);

        $user    = $this->createMock(User::class);
        $academy = new Academy('Test FC', $user);
        $service = $this->makeService($repo);
        $service->assignAcademyToStarterLeague($academy, 'EN');

        $this->assertSame($league, $academy->getCurrentLeague());
    }
}
