<?php

namespace App\Tests\Service;

use App\Entity\FacilityTemplate;
use App\Repository\FacilityTemplateRepository;
use App\Repository\NpcClubRepository;
use App\Service\LeagueService;
use App\Service\NpcClubGenerationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class NpcClubGenerationServiceLeagueTest extends TestCase
{
    public function testGenerateClubsCallsAssignForEachClub(): void
    {
        $facilityRepo = $this->createMock(FacilityTemplateRepository::class);
        $facilityRepo->method('findBy')->willReturn([]);

        $npcClubRepo = $this->createMock(NpcClubRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);

        $leagueService = $this->createMock(LeagueService::class);
        $leagueService->expects($this->exactly(2))
            ->method('assignClubToLeague');

        $service = new NpcClubGenerationService($em, $facilityRepo, $npcClubRepo, $leagueService);
        $clubs   = $service->generateClubs(2, 4, 'EN');

        $this->assertCount(2, $clubs);
    }
}
