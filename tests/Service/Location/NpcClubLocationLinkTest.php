<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Entity\NpcClub;
use App\Enum\CitySize;
use App\Enum\Country;
use App\Enum\Location\CountryProfileStatus;
use App\Service\CountryContent\CountryContentRegistry;
use App\Service\Location\LocationCatalogueService;
use App\Service\NpcClubGenerationService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Generation links each NPC club to the town it was named after — and survives there being no
 * town at all.
 *
 * The second half is the one that would break production. A country generated before its
 * locations are seeded falls back to LocationCatalogueService::PLACEHOLDER_PLACES, whose entries
 * carry no id; the generator must leave the link null rather than fail. That path is reachable
 * from `app:country:bootstrap` and from the admin "Generate Clubs" action on any country whose
 * locations have not been seeded yet.
 */
class NpcClubLocationLinkTest extends KernelTestCase
{
    private const COUNTRY = 'ZN';

    private EntityManagerInterface $em;
    private Connection $connection;
    private NpcClubGenerationService $generator;

    protected function setUp(): void
    {
        self::bootKernel();

        $c = self::getContainer();

        $this->em         = $c->get(EntityManagerInterface::class);
        $this->connection = $c->get(Connection::class);
        $this->generator  = $c->get(NpcClubGenerationService::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    public function testTheGeneratorsPlaceRowsCarryTheLocationId(): void
    {
        $this->seedTowns();

        $places = self::getContainer()->get(LocationCatalogueService::class)
            ->placesForGeneration(self::COUNTRY);

        self::assertNotEmpty($places);

        // findForGeneration() projects the id and its mapper carries it through; without both,
        // the generator has nothing to link with.
        foreach ($places as $place) {
            self::assertArrayHasKey('id', $place);
            self::assertNotSame('', $place['id']);
        }
    }

    public function testClassificationPreservesTheId(): void
    {
        $this->seedTowns();

        $places    = self::getContainer()->get(LocationCatalogueService::class)
            ->placesForGeneration(self::COUNTRY);
        $classified = $this->generator->classifyPlaces($places);

        // classify() sorts and annotates BY REFERENCE rather than rebuilding rows, which is what
        // lets an extra key survive the trip. If it ever starts rebuilding them, the id is the
        // first casualty and the link silently stops being set.
        foreach ($classified as $place) {
            self::assertArrayHasKey('id', $place);
            self::assertArrayHasKey('city_size', $place);
        }
    }

    public function testGeneratedClubsAreLinkedToTheTownTheyAreNamedAfter(): void
    {
        $towns = $this->seedTowns();
        $byId  = [];
        foreach ($towns as $town) {
            $byId[(string) $town->getId()] = $town->getTown();
        }

        $clubs = $this->generator->generateClubs(4, 1, self::COUNTRY);

        self::assertCount(4, $clubs);

        foreach ($clubs as $club) {
            self::assertNotNull($club->getLocation(), $club->getName() . ' was not linked');

            $town = $byId[(string) $club->getLocation()->getId()] ?? null;
            self::assertNotNull($town);

            // The name is built as "{town} {affix}" for this country, so the link and the name
            // must agree — a link pointing at a different town would be worse than none.
            self::assertStringContainsString($town, $club->getName());
        }
    }

    public function testGeneratedClubsCopyTheTownsFacts(): void
    {
        $this->seedTowns();

        $club = $this->generator->generateClubs(1, 1, self::COUNTRY)[0];

        self::assertSame($club->getLocation()->getRegion(), $club->getRegion());
        self::assertSame($club->getLocation()->getCitySize(), $club->getCitySize());
        self::assertSame($club->getLocation()->getPopulation(), $club->getPopulationSize());
    }

    public function testACountryWithNoLocationsStillGenerates(): void
    {
        // No locations seeded at all — the placeholder path. This is reachable in production
        // from app:country:bootstrap on a country whose towns have not been seeded yet.
        $placeholderCountry = $this->countryWithoutPlaces();

        if ($placeholderCountry === null) {
            self::markTestSkipped('Every Country case now has registry places.');
        }

        $clubs = $this->generator->generateClubs(2, 1, $placeholderCountry->value);

        self::assertCount(2, $clubs, 'generation must not fail when there is no town to link');

        foreach ($clubs as $club) {
            // Null rather than an error: PLACEHOLDER_PLACES entries carry no id and never will
            // be location rows ("Capital FC", "Northern Town").
            self::assertNull($club->getLocation());
            self::assertNotSame('', $club->getName());
        }

        $this->connection->executeStatement(
            'DELETE FROM npc_club WHERE country = :c',
            ['c' => $placeholderCountry->value],
        );
    }

    // ───────────────────────── helpers ─────────────────────────

    private function countryWithoutPlaces(): ?Country
    {
        foreach (Country::cases() as $case) {
            if (CountryContentRegistry::places($case) === null
                && $this->locationCount($case->value) === 0) {
                return $case;
            }
        }

        return null;
    }

    private function locationCount(string $code): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM location WHERE country_code = :c',
            ['c' => $code],
        );
    }

    /** @return list<Location> */
    private function seedTowns(): array
    {
        $country = new CountryProfile(self::COUNTRY, 'Linkland');
        $country->setStatus(CountryProfileStatus::LIVE);
        $this->em->persist($country);

        $towns = [];

        foreach ([['Alphaville', 500_000, CitySize::BIG], ['Betatown', 100_000, CitySize::MEDIUM],
                  ['Gammaford', 20_000, CitySize::SMALL], ['Deltabury', 10_000, CitySize::SMALL]] as [$name, $pop, $size]) {
            $location = new Location($country, $name, strtolower(self::COUNTRY . '-' . $name), strtolower($name));
            $location->setRegion('Link Region');
            $location->setPopulation($pop);
            $location->setCitySize($size);
            $location->setCoordinates(53.0, -2.0);
            $location->setLandPence(1_000_00);
            $location->setLandValuedAt(new \DateTimeImmutable());

            $this->em->persist($location);
            $this->em->persist(new LandPlot($location, 1));

            $towns[] = $location;
        }

        $this->em->flush();

        return $towns;
    }

    private function purge(): void
    {
        $c = $this->connection;

        $c->executeStatement('DELETE FROM npc_club WHERE country = :x', ['x' => self::COUNTRY]);
        $c->executeStatement("DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => self::COUNTRY]);
        $c->executeStatement('DELETE FROM location WHERE country_code = :x', ['x' => self::COUNTRY]);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :x', ['x' => self::COUNTRY]);
    }
}
