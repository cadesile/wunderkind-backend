<?php

declare(strict_types=1);

namespace App\Tests\Service\Location\Suggestion;

use App\Entity\Admin;
use App\Enum\Country;
use App\Repository\Location\CountryProfileRepository;
use App\Service\Location\LocationSeedService;
use App\Service\Location\Suggestion\TownSuggestionService;
use App\Service\Location\Suggestion\TownSuggestionValidator;
use App\Tests\Support\FakeTownSuggester;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Suggested towns, from the reply to the rows.
 *
 * Uses TN (Tunisia, 30 registry towns) so nothing else in the suite collides, and a fake
 * suggester throughout — the suite must never make a real, billed call.
 *
 * The properties worth protecting here are the same ones importPlaceNames() protects: the new
 * rows carry no fabricated geography, and nothing that already existed is touched.
 */
class TownSuggestionServiceTest extends KernelTestCase
{
    private const COUNTRY = Country::TN;

    private EntityManagerInterface $em;
    private Connection $connection;
    private LocationSeedService $seedService;
    private FakeTownSuggester $suggester;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em          = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection  = self::getContainer()->get(Connection::class);
        $this->seedService = self::getContainer()->get(LocationSeedService::class);
        $this->suggester   = new FakeTownSuggester();

        $this->purge();
        $this->seedService->seedCountry(self::COUNTRY);
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testSuggestedTownsBecomeLocations(): void
    {
        $before = $this->townCount();

        $this->suggester->queueNames(['Testville', 'Faketown']);
        $result = $this->service()->suggestForCountry(self::COUNTRY, 10);

        $this->assertSame(2, $result['imported']);
        $this->assertSame($before + 2, $this->townCount());
    }

    public function testTheyCarryNoGeographyAndAreLeftForEnrichment(): void
    {
        // The whole point of asking only for names. A fabricated population would be worse
        // than none: EnrichmentValidator keeps a seeded figure when a later one diverges by
        // more than 3x, so a guess written now would permanently shield itself from the
        // better-prompted enrichment call that follows.
        $this->suggester->queueNames(['Testville']);
        $this->service()->suggestForCountry(self::COUNTRY, 10);

        $row = $this->connection->fetchAssociative(
            'SELECT lat, lng, land_pence, population, region, enrichment_status, status
               FROM location WHERE town = :t',
            ['t' => 'Testville'],
        );

        $this->assertNull($row['lat']);
        $this->assertNull($row['lng']);
        $this->assertNull($row['land_pence']);
        $this->assertNull($row['population']);
        $this->assertSame('', $row['region']);
        $this->assertSame('pending', $row['enrichment_status']);
        $this->assertSame('active', $row['status']);
    }

    public function testATownAlreadyHeldIsSkippedNotDuplicated(): void
    {
        $existing = (string) $this->connection->fetchOne(
            'SELECT town FROM location WHERE country_code = :c LIMIT 1',
            ['c' => self::COUNTRY->value],
        );
        $before = $this->townCount();

        $this->suggester->queueNames([$existing, 'Genuinely New']);
        $result = $this->service()->suggestForCountry(self::COUNTRY, 10);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['alreadyHeld']);
        $this->assertSame($before + 1, $this->townCount());
    }

    public function testRunningTheSameSuggestionTwiceAddsNothingTheSecondTime(): void
    {
        $this->suggester->queueNames(['Testville'])->queueNames(['Testville']);

        $this->assertSame(1, $this->service()->suggestForCountry(self::COUNTRY, 10)['imported']);

        $after = $this->townCount();

        $this->assertSame(0, $this->service()->suggestForCountry(self::COUNTRY, 10)['imported']);
        $this->assertSame($after, $this->townCount());
    }

    public function testExistingRowsAreNeverRewritten(): void
    {
        $before = $this->connection->fetchAllAssociative(
            'SELECT id, town, region, population, city_size FROM location WHERE country_code = :c ORDER BY town',
            ['c' => self::COUNTRY->value],
        );

        $this->suggester->queueNames(['Testville']);
        $this->service()->suggestForCountry(self::COUNTRY, 10);

        $after = $this->connection->fetchAllAssociative(
            'SELECT id, town, region, population, city_size FROM location
              WHERE country_code = :c AND town <> :new ORDER BY town',
            ['c' => self::COUNTRY->value, 'new' => 'Testville'],
        );

        $this->assertEquals($before, $after);
    }

    public function testTheTownsWeAlreadyHoldAreOfferedToTheModelAsExclusions(): void
    {
        $this->suggester->queueNames([]);
        $this->service()->suggestForCountry(self::COUNTRY, 10);

        $call = $this->suggester->calls()[0];

        $this->assertSame(self::COUNTRY->value, $call['country']);
        $this->assertNotEmpty($call['exclude']);
        $this->assertSame(10, $call['max']);
    }

    public function testADryRunWritesNothing(): void
    {
        $before = $this->townCount();

        $this->suggester->queueNames(['Testville']);
        $result = $this->service()->suggestForCountry(self::COUNTRY, 10, dryRun: true);

        $this->assertSame(1, $result['imported'], 'A dry run still reports what it would add.');
        $this->assertSame($before, $this->townCount());
    }

    public function testTheCapIsHonoured(): void
    {
        $this->suggester->queueNames(['One', 'Two', 'Three', 'Four']);
        $result = $this->service()->suggestForCountry(self::COUNTRY, 2);

        $this->assertSame(2, $result['imported']);
    }

    public function testARunIsRecordedSoAPaidCallHasATrace(): void
    {
        $this->suggester->queueNames(['Testville']);
        $this->service()->suggestForCountry(self::COUNTRY, 10);

        $run = $this->connection->fetchAssociative(
            "SELECT trigger_type, provider, model, country_code, queued, succeeded, status
               FROM location_enrichment_run WHERE trigger_type = 'town_suggest'",
        );

        $this->assertNotFalse($run, 'A paid call must leave a record of what it was.');
        $this->assertSame('fake', $run['provider']);
        $this->assertSame(self::COUNTRY->value, $run['country_code']);
        $this->assertSame(1, (int) $run['succeeded']);
        $this->assertSame('complete', $run['status']);
    }

    public function testTheRequestingAdminIsRecordedOnTheRun(): void
    {
        // This is the path the admin button takes, and it is the one that broke: importNames()
        // ends with em->clear(), so an Admin captured before the import is detached by the
        // time the run is written. Passing null — as every other test here does — never
        // exercised it.
        $admin = new Admin('suggest-run-' . bin2hex(random_bytes(4)) . '@example.com');
        $admin->setPassword('x');
        $this->em->persist($admin);
        $this->em->flush();
        $adminId = (string) $admin->getId();

        $this->suggester->queueNames(['Testville']);
        $this->service()->suggestForCountry(self::COUNTRY, 10, false, $admin);

        $recorded = $this->connection->fetchOne(
            "SELECT requested_by_id FROM location_enrichment_run WHERE trigger_type = 'town_suggest'",
        );

        self::assertSame($adminId, (string) $recorded);

        $this->connection->executeStatement('DELETE FROM location_enrichment_run WHERE requested_by_id = :id', ['id' => $adminId]);
        $this->connection->executeStatement('DELETE FROM admin WHERE id = :id', ['id' => $adminId]);
    }

    public function testACountryWithNoProfileIsRefusedRatherThanHalfCreated(): void
    {
        $this->connection->executeStatement('DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)', ['c' => self::COUNTRY->value]);
        $this->connection->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::COUNTRY->value]);
        $this->connection->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::COUNTRY->value]);

        $this->suggester->queueNames(['Testville']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no country profile/');

        $this->service()->suggestForCountry(self::COUNTRY, 10);
    }

    private function service(): TownSuggestionService
    {
        return new TownSuggestionService(
            $this->suggester,
            new TownSuggestionValidator(),
            $this->seedService,
            self::getContainer()->get(CountryProfileRepository::class),
            self::getContainer()->get(\App\Repository\Location\TownSuggestionBatchRepository::class),
            $this->em,
            $this->connection,
            new NullLogger(),
        );
    }

    private function townCount(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM location WHERE country_code = :c',
            ['c' => self::COUNTRY->value],
        );
    }

    private function purge(): void
    {
        $code = self::COUNTRY->value;

        $this->connection->executeStatement("DELETE FROM location_enrichment_run WHERE country_code = :c", ['c' => $code]);
        $this->connection->executeStatement(
            'DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            ['c' => $code],
        );
        $this->connection->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => $code]);
        $this->connection->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => $code]);
        $this->em->clear();
    }
}
