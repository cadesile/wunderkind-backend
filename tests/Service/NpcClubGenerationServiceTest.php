<?php

namespace App\Tests\Service;

use App\Entity\FacilityTemplate;
use App\Entity\NpcClub;
use App\Repository\FacilityTemplateRepository;
use App\Repository\NpcClubRepository;
use App\Service\LeagueService;
use App\Service\NpcClubGenerationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class NpcClubGenerationServiceTest extends TestCase
{
    private function makeService(array $slugs = ['training_pitch', 'north_stand', 'physio_clinic']): NpcClubGenerationService
    {
        $em       = $this->createStub(EntityManagerInterface::class);
        $repo     = $this->createStub(FacilityTemplateRepository::class);
        $clubRepo = $this->createStub(NpcClubRepository::class);

        $templates = array_map(function (string $slug) {
            $t = $this->createStub(FacilityTemplate::class);
            $t->method('getSlug')->willReturn($slug);
            return $t;
        }, $slugs);

        $repo->method('findBy')->willReturn($templates);

        $gameConfigRepo = $this->createStub(\App\Repository\GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn(new \App\Entity\GameConfig());

        return new NpcClubGenerationService($em, $repo, $clubRepo, $this->createStub(LeagueService::class), $gameConfigRepo);
    }

    public function testGeneratesCorrectCount(): void
    {
        $service = $this->makeService();
        $clubs   = $service->generateClubs(5, 3, 'ES');
        $this->assertCount(5, $clubs);
        foreach ($clubs as $club) {
            $this->assertInstanceOf(NpcClub::class, $club);
        }
    }

    public function testClubHasCorrectCountryAndTier(): void
    {
        $service = $this->makeService();
        $clubs   = $service->generateClubs(1, 4, 'DE');
        $this->assertSame('DE', $clubs[0]->getCountry());
        $this->assertSame(4, $clubs[0]->getTier());
    }

    public function testTier1ReputationIsHigh(): void
    {
        $service = $this->makeService();
        $clubs   = $service->generateClubs(10, 1, 'ES');
        foreach ($clubs as $club) {
            $this->assertGreaterThanOrEqual(70, $club->getReputation());
            $this->assertLessThanOrEqual(90, $club->getReputation());
        }
    }

    public function testTier8ReputationIsLow(): void
    {
        $service = $this->makeService();
        $clubs   = $service->generateClubs(10, 8, 'ES');
        foreach ($clubs as $club) {
            $this->assertGreaterThanOrEqual(5, $club->getReputation());
            $this->assertLessThanOrEqual(20, $club->getReputation());
        }
    }

    public function testFacilitiesAreAssigned(): void
    {
        $service = $this->makeService(['training_pitch', 'north_stand', 'physio_clinic']);
        $clubs   = $service->generateClubs(1, 1, 'ES');
        $facs    = $clubs[0]->getFacilities();
        $this->assertArrayHasKey('training_pitch', $facs);
        $this->assertArrayHasKey('north_stand', $facs);
        $this->assertArrayHasKey('physio_clinic', $facs);
    }

    public function testNameIsNonEmpty(): void
    {
        $service = $this->makeService();
        $clubs   = $service->generateClubs(3, 2, 'ES');
        foreach ($clubs as $club) {
            $this->assertNotEmpty($club->getName());
        }
    }
}
