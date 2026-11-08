<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Enum\Country;
use App\Service\CountryContent\CountryContentRegistry;
use App\Service\Location\LocationCatalogueService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The read side of the location table — and the home of the coverage that moved here.
 *
 * NpcClubGenerationServiceTest used to assert that Spain's place list contains Madrid and
 * Barcelona, and that all nine generation-capable countries have places. Those were really
 * claims about the data, and the data now lives in the `location` table, so asserting them
 * against a stubbed repository in a unit test would prove only the stub. They are checked here
 * instead, against rows the real seed command actually wrote.
 *
 * Spain is seeded and purged by this test rather than assumed: `wunderkind_test` is built by
 * doctrine:schema:create and is NOT migration-tracked or seeded, so nothing may depend on
 * another test having left data behind.
 */
class LocationCatalogueServiceTest extends KernelTestCase
{
    /** Spain: 40 curated towns, and the one country whose contents the old test named. */
    private const COUNTRY = 'ES';

    private Connection $connection;
    private LocationCatalogueService $catalogue;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = self::getContainer()->get(Connection::class);
        $this->catalogue  = self::getContainer()->get(LocationCatalogueService::class);

        $this->purge();
        $this->seed();
        $this->catalogue->forget();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            ['c' => self::COUNTRY],
        );
        $this->connection->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::COUNTRY]);
    }

    private function seed(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:locations:seed'));
        $tester->execute(['country' => self::COUNTRY]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    /**
     * Spain really does contain Madrid and Barcelona — asserted against the database.
     *
     * The assertion this class exists to preserve.
     */
    public function testSpainsTownsIncludeMadridAndBarcelona(): void
    {
        $names = $this->catalogue->placeNames(self::COUNTRY);

        self::assertNotEmpty($names);
        self::assertContains('Madrid', $names);
        self::assertContains('Barcelona', $names);
    }

    /** Every town the registry curated for Spain reached the table. */
    public function testEveryCuratedTownWasSeeded(): void
    {
        $curated = CountryContentRegistry::places(Country::from(self::COUNTRY)) ?? [];

        self::assertNotEmpty($curated, 'the registry should still hold Spain while the seed reads from it');
        self::assertCount(count($curated), $this->catalogue->placesForGeneration(self::COUNTRY));

        $names = $this->catalogue->placeNames(self::COUNTRY);

        foreach ($curated as $place) {
            self::assertContains($place['name'], $names, "{$place['name']} did not reach the location table");
        }
    }

    /**
     * The generator's contract: the keys it has always consumed, plus city_size, the id, and
     * land_pence.
     *
     * `id` was added so NpcClubGenerationService can link each generated club back to the town
     * it was named after. `land_pence` was added so it can decide how strong that club is —
     * what a plot costs here is what separates a London club from a Poole one, both of which
     * band BIG; see NpcClubGenerationService::withBandStandings().
     *
     * NULLABLE, AND THE TEST SAYS SO RATHER THAN SKIPPING IT. A town is seeded long before it
     * is priced, and the generator's fallback to population only matters because this key is
     * genuinely absent that often.
     */
    public function testReturnsTheGeneratorsExpectedShape(): void
    {
        $places = $this->catalogue->placesForGeneration(self::COUNTRY);

        self::assertNotEmpty($places);

        foreach (array_slice($places, 0, 5) as $place) {
            self::assertSame(
                ['id', 'name', 'population_size', 'region', 'is_capital', 'city_size', 'land_pence'],
                array_keys($place),
            );
            self::assertIsString($place['name']);
            self::assertIsInt($place['population_size']);
            self::assertIsString($place['region']);
            self::assertIsBool($place['is_capital']);
            self::assertContains($place['city_size'], ['BIG', 'MEDIUM', 'SMALL']);
            self::assertTrue($place['land_pence'] === null || is_int($place['land_pence']));
        }
    }

    /** Ordered by population, descending — the order classifyPlaces() and the pickers expect. */
    public function testOrderedByPopulationDescending(): void
    {
        $populations = array_column($this->catalogue->placesForGeneration(self::COUNTRY), 'population_size');

        $sorted = $populations;
        rsort($sorted);

        self::assertSame($sorted, $populations);
    }

    /** Exactly one capital, and it is BIG — classifyPlaces() forces that regardless of rank. */
    public function testExactlyOneCapitalAndItIsBig(): void
    {
        $capitals = array_values(array_filter(
            $this->catalogue->placesForGeneration(self::COUNTRY),
            static fn (array $p): bool => $p['is_capital'],
        ));

        self::assertCount(1, $capitals);
        self::assertSame('BIG', $capitals[0]['city_size']);
    }

    /** A country with nothing seeded returns [] — callers keep their own fallback. */
    public function testUnseededCountryReturnsEmpty(): void
    {
        self::assertSame([], $this->catalogue->placesForGeneration('ZZ'));
        self::assertFalse($this->catalogue->hasPlaces('ZZ'));
        self::assertSame(0, $this->catalogue->countPlaces('ZZ'));
    }

    /**
     * ...but the placeholder accessor substitutes the six generic towns.
     *
     * That fallback is the only thing between a country with no locations and a crash during
     * world generation, and `app:country:bootstrap --force` documents the resulting "Capital
     * FC" names in its own warning text.
     */
    public function testUnseededCountryFallsBackToPlaceholders(): void
    {
        $places = $this->catalogue->placesForGenerationOrPlaceholder('ZZ');

        self::assertSame(LocationCatalogueService::PLACEHOLDER_PLACES, $places);
        self::assertSame('Capital', $places[0]['name']);
    }

    /**
     * One query per country per process.
     *
     * The memo is what keeps repointing generation at the database from turning a const lookup
     * into a query per club — `generateClubName()` runs once per spoof entrant, and
     * `app:country:bootstrap` generates eight tiers in one process.
     */
    /**
     * The end-to-end proof that world generation now runs off the database.
     *
     * Everything else here tests the catalogue in isolation. This one drives the real
     * NpcClubGenerationService from the container against really-seeded rows and asserts the
     * club names it produces are built from real Spanish towns — which is the only check that
     * actually demonstrates the 865 records moved out of PHP without breaking the thing that
     * consumed them.
     */
    public function testGeneratedClubNamesUseRealSeededTowns(): void
    {
        /** @var \App\Service\NpcClubGenerationService $generator */
        $generator = self::getContainer()->get(\App\Service\NpcClubGenerationService::class);

        $towns = $this->catalogue->placeNames(self::COUNTRY);
        self::assertNotEmpty($towns);

        $used  = [];
        $names = [];

        for ($i = 0; $i < 12; $i++) {
            $name    = $generator->generateClubName(self::COUNTRY, $used, 1);
            $used[]  = $name;
            $names[] = $name;
        }

        self::assertCount(12, array_unique($names), 'generated names should be unique');

        foreach ($names as $name) {
            $matchedATown = false;

            foreach ($towns as $town) {
                if (str_contains($name, $town)) {
                    $matchedATown = true;
                    break;
                }
            }

            self::assertTrue(
                $matchedATown,
                "'{$name}' contains no seeded town — generation is not reading the location table",
            );
        }

        // And the placeholder fallback is NOT in play: "Capital FC" would mean the country's
        // rows were invisible to the generator.
        foreach ($names as $name) {
            self::assertStringNotContainsString('Capital', $name);
        }
    }

    public function testResultIsMemoisedPerCountry(): void
    {
        // Warm the memo.
        self::assertNotEmpty($this->catalogue->placesForGeneration(self::COUNTRY));

        // Proven by behaviour rather than by counting queries: empty the table, then ask again.
        // Getting rows back is only possible if no query was issued.
        $this->purge();

        self::assertNotEmpty(
            $this->catalogue->placesForGeneration(self::COUNTRY),
            'a repeat call should come from the memo, not from the database',
        );

        // And forget() really does drop it, so a long-lived process that has just written
        // locations can read them back.
        $this->catalogue->forget(self::COUNTRY);

        self::assertSame(
            [],
            $this->catalogue->placesForGeneration(self::COUNTRY),
            'forget() should force a fresh read',
        );
    }
}
