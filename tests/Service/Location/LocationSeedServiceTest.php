<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Enum\Country;
use App\Service\Location\LocationSeedService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The shared seeding implementation behind both app:locations:seed and the admin Seed button.
 *
 * The command's own test covers the CLI surface; this covers the service the button also calls,
 * so neither face can be the only thing holding a guarantee.
 */
class LocationSeedServiceTest extends KernelTestCase
{
    /** A real registry country, because seeding reads its town list from there. */
    private const SEEDABLE = 'WA';

    private LocationSeedService $service;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->service    = self::getContainer()->get(LocationSeedService::class);
        $this->connection = self::getContainer()->get(Connection::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    public function testAnUnknownCodeExplainsWhatToDoAboutIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // The message an operator sees in the admin flash, so it has to name the actual fix.
        $this->expectExceptionMessageMatches('/not an App\\\\Enum\\\\Country case/');

        $this->service->resolveCountry('QQ');
    }

    public function testACountryWithNoPlaceDataSaysSoRatherThanSeedingNothing(): void
    {
        // A real enum case the registry has no towns for. Silently seeding zero rows would look
        // identical to success.
        $withoutPlaces = null;

        foreach (Country::cases() as $case) {
            if (\App\Service\CountryContent\CountryContentRegistry::places($case) === null) {
                $withoutPlaces = $case;
                break;
            }
        }

        if ($withoutPlaces === null) {
            self::markTestSkipped('Every Country case now has registry places.');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nothing to seed/');

        $this->service->resolveCountry($withoutPlaces->value);
    }

    public function testSeedableCountriesAreOnlyThoseWithPlaceData(): void
    {
        $seedable = $this->service->seedableCountries();

        self::assertNotEmpty($seedable);

        foreach ($seedable as $country) {
            self::assertNotNull(
                \App\Service\CountryContent\CountryContentRegistry::places($country),
                $country->value . ' was offered as seedable but has no places',
            );
        }
    }

    public function testSeedingWritesTownsWithNoPlotsAndNoGeography(): void
    {
        $result = $this->service->seedCountry(Country::from(self::SEEDABLE));

        self::assertGreaterThan(0, $result['locations']);
        self::assertSame('seeded', $result['outcome']);

        // No plots, and the result no longer reports a count for them — it used to report one
        // per town, which stopped being true when plots became something pricing earns.
        self::assertArrayNotHasKey('plots', $result);
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM land_plot p JOIN location l ON l.id = p.location_id WHERE l.country_code = :c',
            ['c' => self::SEEDABLE],
        ), 'an unpriced town must have no plots');

        // lat, lng and land_pence are deliberately NULL — there is no such data in PHP, and 0/0
        // would put every un-enriched town at the same point in the Atlantic.
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM location WHERE country_code = :c AND (lat IS NOT NULL OR lng IS NOT NULL OR land_pence IS NOT NULL)',
            ['c' => self::SEEDABLE],
        ));
    }

    public function testPopulationAndRegionAreSeededWithProvenance(): void
    {
        $this->service->seedCountry(Country::from(self::SEEDABLE));

        $row = $this->connection->fetchAssociative(
            'SELECT population, region, population_source FROM location
              WHERE country_code = :c AND population IS NOT NULL LIMIT 1',
            ['c' => self::SEEDABLE],
        );

        self::assertNotFalse($row);
        self::assertNotSame('', (string) $row['region']);
        self::assertSame(LocationSeedService::POPULATION_SOURCE, $row['population_source']);
    }

    public function testASecondRunIsASkipNotAnUpsert(): void
    {
        $this->service->seedCountry(Country::from(self::SEEDABLE));
        $second = $this->service->seedCountry(Country::from(self::SEEDABLE));

        // These rows get hand-corrected and then carry real money. An upsert would stomp the
        // correction; the skip is the whole safety property.
        self::assertSame(0, $second['locations']);
        self::assertStringContainsString('skipped', $second['outcome']);
    }

    public function testForcingAddsMissingTownsWithoutRewritingExistingOnes(): void
    {
        $this->service->seedCountry(Country::from(self::SEEDABLE));

        // Remove one town, then force. Only the gap should be refilled.
        $victim = $this->connection->fetchAssociative(
            'SELECT id, town FROM location WHERE country_code = :c ORDER BY town LIMIT 1',
            ['c' => self::SEEDABLE],
        );
        $this->connection->executeStatement('DELETE FROM land_plot WHERE location_id = :id', ['id' => $victim['id']]);
        $this->connection->executeStatement('DELETE FROM location WHERE id = :id', ['id' => $victim['id']]);

        $before = $this->countFor(self::SEEDABLE);
        $forced = $this->service->seedCountry(Country::from(self::SEEDABLE), forced: true);

        self::assertSame(1, $forced['locations'], 'only the missing town is added');
        self::assertSame($before + 1, $this->countFor(self::SEEDABLE));
        self::assertStringContainsString('forced', $forced['outcome']);
    }

    public function testADryRunReportsTheCountsAndWritesNothing(): void
    {
        $result = $this->service->seedCountry(Country::from(self::SEEDABLE), dryRun: true);

        self::assertGreaterThan(0, $result['locations'], 'the counts are what a real run would do');
        self::assertSame(0, $this->countFor(self::SEEDABLE), 'and nothing was written');
    }

    public function testCitySizesAreClassifiedAcrossTheWholeCountry(): void
    {
        $result = $this->service->seedCountry(Country::from(self::SEEDABLE));

        // city_size is a property of the whole country's set, so it can only be computed once
        // every town is in — which is why it is reported by the seed rather than per row.
        self::assertNotEmpty($result['citySizes']);
        self::assertSame($result['locations'], array_sum($result['citySizes']));
    }

    private function countFor(string $code): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM location WHERE country_code = :c',
            ['c' => $code],
        );
    }

    private function purge(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            ['c' => self::SEEDABLE],
        );
        $this->connection->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::SEEDABLE]);
        $this->connection->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::SEEDABLE]);
    }
}
