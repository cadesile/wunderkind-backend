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

    public function testGetPlaceNamesReturnsKnownCountryData(): void
    {
        $service = $this->makeService();
        $places  = $service->getPlaceNames('ES');

        $this->assertNotEmpty($places);
        $this->assertContains('Madrid', $places);
        $this->assertContains('Barcelona', $places);
    }

    public function testGetPlaceNamesReturnsEmptyArrayForUnknownCountry(): void
    {
        $service = $this->makeService();
        $this->assertSame([], $service->getPlaceNames('XX'));
    }

    public function testGetSuffixesReturnsKnownCountryData(): void
    {
        $service  = $this->makeService();
        $suffixes = $service->getSuffixes('EN');

        $this->assertNotEmpty($suffixes);
        $this->assertContains('FC', $suffixes);
        $this->assertContains('United', $suffixes);
    }

    public function testGetSuffixesReturnsEmptyArrayForUnknownCountry(): void
    {
        $service = $this->makeService();
        $this->assertSame([], $service->getSuffixes('XX'));
    }

    public function testGetPlaceNamesCoversAllNineGenerationCapableCountries(): void
    {
        $service = $this->makeService();
        foreach (['ES', 'EN', 'DE', 'IT', 'FR', 'BR', 'AR', 'NL', 'PT'] as $country) {
            $this->assertNotEmpty($service->getPlaceNames($country), "Expected place names for {$country}");
            $this->assertNotEmpty($service->getSuffixes($country), "Expected suffixes for {$country}");
        }
    }

    public function testGetPlaceDataReturnsStructuredRows(): void
    {
        $service = $this->makeService();
        $places  = $service->getPlaceData('EN');

        $this->assertNotEmpty($places);
        $first = $places[0];
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('population_size', $first);
        $this->assertArrayHasKey('region', $first);
    }

    public function testGetPlaceDataMarksExactlyOneCapitalPerCountry(): void
    {
        $service = $this->makeService();
        foreach (['ES', 'EN', 'DE', 'IT', 'FR', 'BR', 'AR', 'NL', 'PT'] as $country) {
            $capitals = array_filter($service->getPlaceData($country), fn(array $p) => $p['is_capital'] ?? false);
            $this->assertCount(1, $capitals, "Expected exactly one capital for {$country}");
        }
    }

    public function testClassifyPlacesForcesCapitalToBig(): void
    {
        $service = $this->makeService();
        $classified = $service->classifyPlaces([
            ['name' => 'Capital', 'population_size' => 1000, 'region' => 'R', 'is_capital' => true],
            ['name' => 'Big', 'population_size' => 900000, 'region' => 'R'],
            ['name' => 'Mid', 'population_size' => 50000, 'region' => 'R'],
            ['name' => 'Small', 'population_size' => 1000, 'region' => 'R'],
        ]);

        $bySize = [];
        foreach ($classified as $p) {
            $bySize[$p['name']] = $p['city_size'];
        }

        $this->assertSame(\App\Enum\CitySize::BIG, $bySize['Capital']);
    }

    public function testClassifyPlacesBucketsByPopulationPercentile(): void
    {
        $service = $this->makeService();
        // 10 places, population 100..1000 descending — top 20% (2) BIG, bottom 50% (5) SMALL, rest MEDIUM.
        $places = [];
        for ($i = 10; $i >= 1; $i--) {
            $places[] = ['name' => "City{$i}", 'population_size' => $i * 100, 'region' => 'R'];
        }

        $classified = $service->classifyPlaces($places);
        $bySize = [];
        foreach ($classified as $p) {
            $bySize[$p['name']] = $p['city_size'];
        }

        $this->assertSame(\App\Enum\CitySize::BIG, $bySize['City10']);
        $this->assertSame(\App\Enum\CitySize::BIG, $bySize['City9']);
        $this->assertSame(\App\Enum\CitySize::SMALL, $bySize['City1']);
        $this->assertSame(\App\Enum\CitySize::SMALL, $bySize['City5']);
        $this->assertSame(\App\Enum\CitySize::MEDIUM, $bySize['City6']);
    }

    public function testGetRemainingPlaceNamesExcludesCuratedNames(): void
    {
        $service   = $this->makeService();
        $curated   = $service->getPlaceNames('EN');
        $remaining = $service->getRemainingPlaceNames('EN');

        $this->assertNotEmpty($remaining);
        foreach ($curated as $name) {
            $this->assertNotContains($name, $remaining, "Curated name '{$name}' should not also appear in remaining");
        }
    }

    public function testGetRemainingPlaceNamesContainsNonCuratedOriginalCities(): void
    {
        $service   = $this->makeService();
        $remaining = $service->getRemainingPlaceNames('EN');

        // 'Prestwich' was in the original EN list but is not one of the ~40 curated entries.
        $this->assertContains('Prestwich', $remaining);
    }

    public function testGetRemainingPlaceNamesIsFilteredPerCountry(): void
    {
        $service = $this->makeService();

        $en = $service->getRemainingPlaceNames('EN');
        $es = $service->getRemainingPlaceNames('ES');

        $this->assertNotContains('Sevilla', $en);
        $this->assertNotContains('Manchester', $es);
    }

    public function testGetRemainingPlaceNamesReturnsEmptyArrayForUnknownCountry(): void
    {
        $service = $this->makeService();
        $this->assertSame([], $service->getRemainingPlaceNames('XX'));
    }

    public function testGetSuffixesStillReturnsBothPrestigeAndGenericWords(): void
    {
        $service  = $this->makeService();
        $suffixes = $service->getSuffixes('EN');

        // Prestige word
        $this->assertContains('United', $suffixes);
        // Generic word
        $this->assertContains('FC', $suffixes);
    }

    public function testPickSuffixForCitySizeBigAlwaysUsesPrestigePool(): void
    {
        $service  = $this->makeService();
        $prestige = ['United', 'City'];
        $generic  = ['Town', 'Rovers'];

        for ($i = 0; $i < 20; $i++) {
            $picked = $service->pickSuffixForCitySize(\App\Enum\CitySize::BIG, $prestige, $generic);
            $this->assertContains($picked, $prestige);
        }
    }

    public function testPickSuffixForCitySizeSmallAlwaysUsesGenericPool(): void
    {
        $service  = $this->makeService();
        $prestige = ['United', 'City'];
        $generic  = ['Town', 'Rovers'];

        for ($i = 0; $i < 20; $i++) {
            $picked = $service->pickSuffixForCitySize(\App\Enum\CitySize::SMALL, $prestige, $generic);
            $this->assertContains($picked, $generic);
        }
    }

    public function testPickSuffixForCitySizeMediumUsesEitherPool(): void
    {
        $service  = $this->makeService();
        $prestige = ['United'];
        $generic  = ['Town'];
        $seenPrestige = false;
        $seenGeneric  = false;

        for ($i = 0; $i < 40; $i++) {
            $picked = $service->pickSuffixForCitySize(\App\Enum\CitySize::MEDIUM, $prestige, $generic);
            if ($picked === 'United') $seenPrestige = true;
            if ($picked === 'Town') $seenGeneric = true;
        }

        $this->assertTrue($seenPrestige, 'Expected at least one prestige pick across 40 MEDIUM rolls');
        $this->assertTrue($seenGeneric, 'Expected at least one generic pick across 40 MEDIUM rolls');
    }

    public function testPickWeightedPlaceAlwaysReturnsAPlace(): void
    {
        $service = $this->makeService();
        $classified = $service->classifyPlaces([
            ['name' => 'Big', 'population_size' => 900000, 'region' => 'R'],
            ['name' => 'Small', 'population_size' => 1000, 'region' => 'R'],
        ]);

        $picked = $service->pickWeightedPlace($classified, ['big' => 70, 'medium' => 25, 'small' => 5]);
        $this->assertContains($picked['name'], ['Big', 'Small']);
    }

    public function testPickWeightedPlaceFavorsBigBucketWhenWeighted(): void
    {
        $service = $this->makeService();
        $classified = $service->classifyPlaces([
            ['name' => 'Big', 'population_size' => 900000, 'region' => 'R'],
            ['name' => 'Small', 'population_size' => 1000, 'region' => 'R'],
        ]);

        $bigCount = 0;
        for ($i = 0; $i < 200; $i++) {
            $picked = $service->pickWeightedPlace($classified, ['big' => 95, 'medium' => 4, 'small' => 1]);
            if ($picked['name'] === 'Big') $bigCount++;
        }

        $this->assertGreaterThan(150, $bigCount, 'Expected the heavily-weighted BIG bucket to dominate picks');
    }

    public function testGenerateClubsAtTier1SkewsTowardBigCities(): void
    {
        $service = $this->makeService();
        $clubs   = $service->generateClubs(80, 1, 'ES');

        $bigCount = 0;
        foreach ($clubs as $club) {
            if ($club->getCitySize() === \App\Enum\CitySize::BIG) $bigCount++;
        }

        // Default tier-1 weight is 70% BIG — with 80 clubs, expect a clear majority to be BIG.
        $this->assertGreaterThan(40, $bigCount);
    }

    public function testGenerateClubsAtTier8SkewsTowardSmallCities(): void
    {
        $service = $this->makeService();
        $clubs   = $service->generateClubs(80, 8, 'ES');

        $smallCount = 0;
        foreach ($clubs as $club) {
            if ($club->getCitySize() === \App\Enum\CitySize::SMALL) $smallCount++;
        }

        // Default tier-8 weight is 70% SMALL — with 80 clubs, expect a clear majority to be SMALL.
        $this->assertGreaterThan(40, $smallCount);
    }
}
