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
        $facilityRepo = $this->createStub(FacilityTemplateRepository::class);
        $facilityRepo->method('findBy')->willReturn([]);

        $npcClubRepo = $this->createStub(NpcClubRepository::class);

        $em = $this->createStub(EntityManagerInterface::class);

        $leagueService = $this->createMock(LeagueService::class);
        $leagueService->expects($this->exactly(2))
            ->method('assignClubToLeague');

        $gameConfigRepo = $this->createStub(\App\Repository\GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn(new \App\Entity\GameConfig());

        // Place data moved to the `location` table, so the generator now takes a catalogue.
        // Stubbed at the repository so this stays a unit test of league assignment.
        $locationRepo = $this->createStub(\App\Repository\Location\LocationRepository::class);
        $locationRepo->method('findForGeneration')->willReturn([
            ['name' => 'Alpha', 'population_size' => 800_000, 'region' => 'North', 'is_capital' => true, 'city_size' => 'BIG'],
            ['name' => 'Bravo', 'population_size' => 400_000, 'region' => 'South', 'is_capital' => false, 'city_size' => 'MEDIUM'],
            ['name' => 'Charlie', 'population_size' => 90_000, 'region' => 'East', 'is_capital' => false, 'city_size' => 'SMALL'],
        ]);

        $service = new NpcClubGenerationService(
            $em,
            $facilityRepo,
            $npcClubRepo,
            $leagueService,
            $gameConfigRepo,
            new \App\Service\Location\LocationCatalogueService($locationRepo),
        );
        $clubs   = $service->generateClubs(2, 4, 'EN');

        $this->assertCount(2, $clubs);
    }
}
