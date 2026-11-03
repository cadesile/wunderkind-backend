<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Enum\CitySize;
use App\Enum\Location\EnrichmentStatus;
use App\Service\CountryContent\CountryContentRegistry;
use App\Enum\Country;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:locations:seed against the real database.
 *
 * Tunisia is used throughout rather than England: it is one of the smallest sets (30 towns),
 * nothing else in the suite touches it, and every assertion here is about the mechanism rather
 * than about which towns exist.
 *
 * The things actually worth protecting:
 *   - --dry-run writes nothing at all (it is the only safe way to inspect a 25-country sweep)
 *   - a second run is a no-op, because these rows get hand-corrected and an upsert-on-deploy
 *     model would stomp an admin's work
 *   - --force-country adds only MISSING towns, and only to the country it names
 *   - coordinates and land values are left NULL, not zeroed
 */
class SeedLocationsCommandTest extends KernelTestCase
{
    private const COUNTRY = 'TN';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    /** Plots before locations — the FK is ON DELETE RESTRICT on purpose. */
    private function purge(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            ['c' => self::COUNTRY],
        );
        $this->connection->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::COUNTRY]);
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:locations:seed'));
    }

    private function runSeed(array $input): CommandTester
    {
        $tester = $this->tester();
        $tester->execute($input);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $tester;
    }

    private function locationCount(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM location WHERE country_code = :c',
            ['c' => self::COUNTRY],
        );
    }

    private function plotCount(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM land_plot p JOIN location l ON l.id = p.location_id WHERE l.country_code = :c',
            ['c' => self::COUNTRY],
        );
    }

    private function expectedTownCount(): int
    {
        return count(CountryContentRegistry::places(Country::from(self::COUNTRY)) ?? []);
    }

    public function testDryRunWritesNothing(): void
    {
        $tester = $this->runSeed(['country' => self::COUNTRY, '--dry-run' => true]);

        self::assertSame(0, $this->locationCount(), 'a dry run must not write a single row');
        self::assertSame(0, $this->plotCount());
        self::assertStringContainsString('Dry run', $tester->getDisplay());
    }

    public function testSeedsOneLocationAndOnePlotPerRegistryTown(): void
    {
        $this->runSeed(['country' => self::COUNTRY]);

        $expected = $this->expectedTownCount();

        self::assertGreaterThan(0, $expected, 'the registry should have towns for this country');
        self::assertSame($expected, $this->locationCount());
        // Exactly one plot each, so one-club-per-town holds until an operator adds more.
        self::assertSame($expected, $this->plotCount());
    }

    /**
     * Coordinates and land values stay NULL.
     *
     * Zeroing them would put every un-enriched town at 0,0 — a point in the Atlantic that the
     * rival pass would rank as every other un-enriched town's nearest neighbour.
     */
    public function testLeavesCoordinatesAndLandValueNullForEnrichment(): void
    {
        $this->runSeed(['country' => self::COUNTRY]);

        $incomplete = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM location
              WHERE country_code = :c AND lat IS NULL AND lng IS NULL AND land_pence IS NULL',
            ['c' => self::COUNTRY],
        );

        self::assertSame($this->locationCount(), $incomplete, 'every seeded row should await enrichment');

        $statuses = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT enrichment_status FROM location WHERE country_code = :c',
            ['c' => self::COUNTRY],
        );

        self::assertSame([EnrichmentStatus::PENDING->value], $statuses);
    }

    /** Region and population ARE seeded, and say where they came from. */
    public function testSeedsRegionAndPopulationWithProvenance(): void
    {
        $this->runSeed(['country' => self::COUNTRY]);

        $row = $this->connection->fetchAssociative(
            'SELECT region, population, population_source FROM location
              WHERE country_code = :c AND population IS NOT NULL ORDER BY population DESC LIMIT 1',
            ['c' => self::COUNTRY],
        );

        self::assertNotFalse($row);
        self::assertNotSame('', (string) $row['region']);
        self::assertGreaterThan(0, (int) $row['population']);
        self::assertStringContainsString('CountryContentRegistry', (string) $row['population_source']);
    }

    /**
     * A second run must not duplicate, and must not rewrite.
     *
     * This is the act-only-when-empty guarantee. These rows get corrected by hand, so an
     * upsert on every deploy would permanently stomp an admin's edits.
     */
    public function testSecondRunIsANoOp(): void
    {
        $this->runSeed(['country' => self::COUNTRY]);
        $after = $this->locationCount();

        $tester = $this->runSeed(['country' => self::COUNTRY]);

        self::assertSame($after, $this->locationCount());
        self::assertSame($after, $this->plotCount());
        self::assertStringContainsString('skipped', $tester->getDisplay());
    }

    /** --force-country adds the missing town back, and nothing else. */
    public function testForceCountryAddsOnlyMissingTowns(): void
    {
        $this->runSeed(['country' => self::COUNTRY]);
        $full = $this->locationCount();

        $victim = (string) $this->connection->fetchOne(
            'SELECT id FROM location WHERE country_code = :c ORDER BY town LIMIT 1',
            ['c' => self::COUNTRY],
        );

        $this->connection->executeStatement('DELETE FROM land_plot WHERE location_id = :id', ['id' => $victim]);
        $this->connection->executeStatement('DELETE FROM location WHERE id = :id', ['id' => $victim]);

        self::assertSame($full - 1, $this->locationCount());

        $this->runSeed(['--force-country' => self::COUNTRY]);

        self::assertSame($full, $this->locationCount(), 'the one missing town should be restored');
        self::assertSame($full, $this->plotCount());
    }

    /**
     * --force-country scopes to the country it names.
     *
     * It did not, at first: with no positional argument it fell through to "seed every
     * country" and wrote all 865 rows across 25 countries. A flag naming one country must not
     * be a 25-country sweep.
     */
    public function testForceCountryDoesNotSeedOtherCountries(): void
    {
        $others = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM location WHERE country_code <> :c',
            ['c' => self::COUNTRY],
        );

        $this->runSeed(['--force-country' => self::COUNTRY]);

        self::assertSame(
            $others,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM location WHERE country_code <> :c',
                ['c' => self::COUNTRY],
            ),
            '--force-country must not touch any other country',
        );
        self::assertSame($this->expectedTownCount(), $this->locationCount());
    }

    /** Slugs are unique per country, since uq_location_country_slug says so. */
    public function testSlugsAreUniqueAndWellFormed(): void
    {
        $this->runSeed(['country' => self::COUNTRY]);

        $slugs = $this->connection->fetchFirstColumn(
            'SELECT slug FROM location WHERE country_code = :c',
            ['c' => self::COUNTRY],
        );

        self::assertSameSize($slugs, array_unique($slugs));

        foreach ($slugs as $slug) {
            self::assertMatchesRegularExpression('/^tn-[a-z0-9-]+$/', (string) $slug, "bad slug: {$slug}");
        }
    }

    /**
     * city_size is persisted, and the bands add up.
     *
     * The classification can only run once every town for the country is in, because the
     * 20/30/50 split is a property of the whole set.
     */
    public function testPersistsCitySizeBandsCoveringEveryRow(): void
    {
        $this->runSeed(['country' => self::COUNTRY]);

        $rows = $this->connection->fetchAllKeyValue(
            'SELECT city_size, COUNT(*) FROM location WHERE country_code = :c GROUP BY city_size',
            ['c' => self::COUNTRY],
        );

        $total = array_sum(array_map('intval', $rows));

        self::assertSame($this->locationCount(), $total);

        $expected  = $this->expectedTownCount();
        $expectBig = (int) ceil($expected * 0.20);

        self::assertSame($expectBig, (int) ($rows[CitySize::BIG->value] ?? 0));
        self::assertSame((int) ceil($expected * 0.50), (int) ($rows[CitySize::SMALL->value] ?? 0));
    }

    /** A country with no Country enum case cannot be seeded, and says why. */
    public function testUnknownCountryCodeFails(): void
    {
        $tester = $this->tester();
        $tester->execute(['country' => 'QQ']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Country', $tester->getDisplay());
    }

    /** Contradictory scope is refused rather than silently resolved one way. */
    public function testConflictingArgumentAndForceCountryFails(): void
    {
        $tester = $this->tester();
        $tester->execute(['country' => 'EN', '--force-country' => 'TN']);

        self::assertSame(1, $tester->getStatusCode());
    }
}
