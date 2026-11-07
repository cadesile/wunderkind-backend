<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\Location;
use App\Enum\CitySize;
use App\Enum\Country;
use App\Enum\Location\CountryProfileStatus;
use App\Service\Location\LocationClassificationService;
use App\Service\Location\LocationSeedService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Importing the bare town names, and the two guards that stop them wrecking the world they join.
 *
 * The catalogue holds 865 curated towns (population, region) and ~4,200 bare names with nothing
 * but a name. Importing the second set is what makes Prestwich a place you can buy rather than
 * only a word you can build a club name from.
 *
 * The guards matter more than the import. An unpriced town must not shift a city-size percentile
 * band, and must not become a candidate for NPC club generation — otherwise importing 238 names
 * into England would take BIG from 9 towns to 56, promote every existing MEDIUM and SMALL, and
 * re-band a world that was already generated.
 */
class ImportPlaceNamesTest extends KernelTestCase
{
    private const COUNTRY = 'EN';

    private EntityManagerInterface $em;
    private Connection $connection;
    private LocationSeedService $seedService;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em          = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection  = self::getContainer()->get(Connection::class);
        $this->seedService = self::getContainer()->get(LocationSeedService::class);

        $this->purge();
        $this->seedCurated();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    public function testImportedTownsArriveUnpricedAndPending(): void
    {
        $result = $this->seedService->importPlaceNames(Country::EN);

        self::assertGreaterThan(0, $result['imported']);

        $row = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    COUNT(population) AS with_population,
                    COUNT(lat) AS with_coords,
                    COUNT(land_pence) AS with_price,
                    COUNT(*) FILTER (WHERE enrichment_status = 'pending') AS pending
               FROM location
              WHERE country_code = :c AND population IS NULL",
            ['c' => self::COUNTRY],
        );

        // Nothing but a name. Writing a zero population would be worse than writing nothing —
        // it would look like data and would feed straight into city size.
        self::assertSame(0, (int) $row['with_population']);
        self::assertSame(0, (int) $row['with_coords']);
        self::assertSame(0, (int) $row['with_price']);
        self::assertSame((int) $row['total'], (int) $row['pending']);
    }

    public function testImportIsIdempotent(): void
    {
        $first  = $this->seedService->importPlaceNames(Country::EN);
        $second = $this->seedService->importPlaceNames(Country::EN);

        self::assertGreaterThan(0, $first['imported']);
        self::assertSame(0, $second['imported'], 'a second run adds nothing');
        self::assertGreaterThan(0, $second['skipped']);
    }

    public function testRegistryRepeatsAreCountedSeparatelyFromExistingTowns(): void
    {
        $result = $this->seedService->importPlaceNames(Country::EN);

        // Two different reasons to skip, and an operator reading "skipped 3,400" wants to know
        // which: already a location, versus the registry listing the same name twice.
        self::assertArrayHasKey('skipped', $result);
        self::assertArrayHasKey('duplicates', $result);
        self::assertGreaterThan(0, $result['skipped'], 'Liverpool is already a location');
    }

    public function testNearDuplicatesAreReportedAndNotMerged(): void
    {
        $result = $this->seedService->importPlaceNames(Country::EN);

        $reported = array_column($result['nearDuplicates'], 'imported');

        // "Newcastle" against the curated "Newcastle upon Tyne". Both rows exist; a human
        // decides. Auto-merging would also merge "San Sebastián de los Reyes" into "San
        // Sebastián", which are genuinely different places.
        self::assertContains('Newcastle', $reported);

        $both = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM location
              WHERE country_code = :c AND town_normalised IN ('newcastle', 'newcastle upon tyne')",
            ['c' => self::COUNTRY],
        );

        self::assertSame(2, $both, 'both rows survive — nothing was merged');
    }

    // ───────────────────────── the guards ─────────────────────────

    public function testImportedTownsDoNotShiftCitySizeBands(): void
    {
        $classifier = self::getContainer()->get(\App\Service\Location\LocationClassificationService::class);

        // Baseline is a COMPUTED classification, not the hand-set fixture values — otherwise
        // this would be comparing a percentile result against an arbitrary one and failing for
        // the wrong reason.
        $classifier->reclassifyCountry(self::COUNTRY);
        $before = $this->bands();

        $this->seedService->importPlaceNames(Country::EN);
        $classifier->reclassifyCountry(self::COUNTRY);

        $after = $this->bands();

        // THE ASSERTION THIS FILE EXISTS FOR. City size is a percentile across the country's
        // set, so an unpriced town joining it would push every boundary.
        self::assertSame($before['BIG'] ?? 0, $after['BIG'] ?? 0, 'BIG must not move');
        self::assertSame($before['MEDIUM'] ?? 0, $after['MEDIUM'] ?? 0, 'MEDIUM must not move');
    }

    public function testImportedTownsAreNotGeneratable(): void
    {
        $this->seedService->importPlaceNames(Country::EN);
        $this->em->clear();

        $places = self::getContainer()
            ->get(\App\Service\Location\LocationCatalogueService::class)
            ->placesForGeneration(self::COUNTRY);

        // Generating an NPC club in an unpriced town yields a club with an empty region and a
        // population of zero, which then propagates into its city size, its reputation band and
        // the cached world pack.
        self::assertCount(2, $places, 'only the curated towns are generatable');

        $names = array_column($places, 'name');
        sort($names);
        self::assertSame(['Liverpool', 'Newcastle upon Tyne'], $names);
    }

    // ───────────────────────── helpers ─────────────────────────

    /** @return array<string, int> */
    // ── The capital that arrives as a bare name ─────────────────────────────

    public function testACapitalPulledInAsABareNameIsStillFlagged(): void
    {
        // The gap this closes. An AI town pull returns names and nothing else, so a capital
        // arriving that way had no is_capital to be set from — and for the 46 countries with no
        // curated places, that was every capital.
        $this->seedService->importNames(Country::EN, ['London']);

        self::assertFalse($this->isCapital('london'), 'nothing knows it yet');

        self::getContainer()->get(LocationClassificationService::class)
            ->reclassifyCountry(self::COUNTRY);

        self::assertTrue($this->isCapital('london'));
    }

    public function testTheFlaggedCapitalIsForcedBigOnceItIsPriced(): void
    {
        // The flag is only worth setting because of what classify() does with it: London here
        // is the SMALLEST of the three towns and must still come out BIG.
        $this->seedService->importNames(Country::EN, ['London']);

        $this->connection->executeStatement(
            "UPDATE location
                SET population = 1000, lat = 51.5, lng = -0.1,
                    land_pence = 100000, land_valued_at = now()
              WHERE country_code = :c AND town_normalised = 'london'",
            ['c' => self::COUNTRY],
        );

        self::getContainer()->get(LocationClassificationService::class)
            ->reclassifyCountry(self::COUNTRY);

        self::assertSame(
            CitySize::BIG->value,
            $this->connection->fetchOne(
                'SELECT city_size FROM location WHERE country_code = :c AND town_normalised = :t',
                ['c' => self::COUNTRY, 't' => 'london'],
            ),
        );
    }

    public function testAnOrdinaryTownIsNotMistakenForTheCapital(): void
    {
        $this->seedService->importNames(Country::EN, ['Londonderry']);

        self::getContainer()->get(LocationClassificationService::class)
            ->reclassifyCountry(self::COUNTRY);

        self::assertFalse($this->isCapital('londonderry'), 'matched whole, not by prefix');
    }

    private function isCapital(string $normalised): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT is_capital FROM location WHERE country_code = :c AND town_normalised = :t',
            ['c' => self::COUNTRY, 't' => $normalised],
        );
    }

    private function bands(): array
    {
        $out = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT city_size, COUNT(*) AS n FROM location WHERE country_code = :c GROUP BY city_size',
            ['c' => self::COUNTRY],
        ) as $row) {
            $out[(string) $row['city_size']] = (int) $row['n'];
        }

        return $out;
    }

    private function seedCurated(): void
    {
        $country = new CountryProfile(self::COUNTRY, 'Importland');
        $country->setStatus(CountryProfileStatus::LIVE);
        $this->em->persist($country);

        // REAL curated names, chosen for what each one exercises:
        //   Liverpool           — also in the bare list, so it must be SKIPPED
        //   Newcastle upon Tyne — NOT in the bare list, and the bare "Newcastle" is a
        //                         whole-word prefix of it, so it must be REPORTED as a
        //                         possible duplicate and both rows must survive
        foreach ([['Liverpool', 'liverpool', 500_000], ['Newcastle upon Tyne', 'newcastle upon tyne', 300_000]] as [$town, $norm, $pop]) {
            $location = new Location($country, $town, 'en-' . str_replace(' ', '-', $norm), $norm);
            $location->setRegion('R');
            $location->setPopulation($pop);
            $location->setCitySize(CitySize::BIG);
            $location->setCoordinates(53.0, -2.0);
            $location->setLandPence(1_000_00);
            $location->setLandValuedAt(new \DateTimeImmutable());

            $this->em->persist($location);
        }

        $this->em->flush();
    }

    private function purge(): void
    {
        $c = $this->connection;

        $c->executeStatement("UPDATE club SET home_location_id = NULL WHERE home_location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => self::COUNTRY]);
        $c->executeStatement("UPDATE npc_club SET location_id = NULL WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => self::COUNTRY]);
        $c->executeStatement("DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :x)", ['x' => self::COUNTRY]);
        $c->executeStatement('DELETE FROM location WHERE country_code = :x', ['x' => self::COUNTRY]);
        $c->executeStatement('DELETE FROM country_profile WHERE code = :x', ['x' => self::COUNTRY]);
    }
}
