<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Enum\Location\CountryProfileStatus;
use App\Enum\Location\EnrichmentStatus;
use App\Repository\Location\LandPlotRepository;
use App\Repository\Location\LocationRepository;
use App\Service\Location\LocationImportExportService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exporting and importing enriched geography.
 *
 * This exists because enrichment costs money: one billed API call per town plus a human review
 * pass over every AI-sourced land valuation. So the properties under test are the ones that
 * decide whether that spend can actually be reused:
 *
 *   - the round trip is LOSSLESS, or the file is not a backup;
 *   - matching is on (countryCode, slug) and NOT on uuid, or the file only ever imports into the
 *     database it came from;
 *   - an admin lock in the TARGET wins, or importing geography silently throws away the review;
 *   - import is an upsert that deletes nothing, because a location can carry real money.
 */
class LocationImportExportServiceTest extends KernelTestCase
{
    private const SOURCE = 'ZE';
    private const TARGET = 'ZT';

    private EntityManagerInterface $em;
    private Connection $connection;
    private LocationImportExportService $service;
    private LocationRepository $locations;
    private LandPlotRepository $plots;

    protected function setUp(): void
    {
        self::bootKernel();

        $c = self::getContainer();

        $this->em         = $c->get(EntityManagerInterface::class);
        $this->connection = $c->get(Connection::class);
        $this->service    = $c->get(LocationImportExportService::class);
        $this->locations  = $c->get(LocationRepository::class);
        $this->plots      = $c->get(LandPlotRepository::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    // ───────────────────────── export ─────────────────────────

    public function testExportCarriesTheEnrichmentResultAndItsProvenance(): void
    {
        $this->country(self::SOURCE);
        $this->location(self::SOURCE, 'Alpha', enriched: true);

        $row = $this->exportOne(self::SOURCE);

        // The whole point: "this row is already done, do not pay for it again".
        $this->assertSame('enriched', $row['enrichmentStatus']);
        $this->assertSame('anthropic', $row['enrichmentProvider']);
        $this->assertNotNull($row['enrichedAt']);
        $this->assertSame(53.5, $row['lat']);
        $this->assertSame(1_234_00, $row['landPence']);
    }

    public function testExportIsKeyedOnSlugNotUuid(): void
    {
        $this->country(self::SOURCE);
        $this->location(self::SOURCE, 'Alpha');

        $row = $this->exportOne(self::SOURCE);

        // A uuid in the file would make it importable only into the database it came from.
        $this->assertArrayHasKey('slug', $row);
        $this->assertArrayHasKey('countryCode', $row);
        $this->assertArrayNotHasKey('id', $row);
    }

    public function testExportCarriesTheCountryBboxBecauseValidationNeedsIt(): void
    {
        $country = $this->country(self::SOURCE);
        $country->setBbox(['minLat' => 50.0, 'maxLat' => 56.0, 'minLng' => -6.0, 'maxLng' => 2.0]);
        $this->em->flush();

        $data = $this->service->export([self::SOURCE]);

        // Without the bbox, the target environment cannot re-enrich a correction: every AI
        // coordinate is validated against it.
        $this->assertSame(50.0, $data['countries'][0]['bbox']['minLat']);
    }

    public function testExportNeverCarriesClaimsHoldsOrLedgerEntries(): void
    {
        $this->country(self::SOURCE);
        $this->location(self::SOURCE, 'Alpha');

        $json = json_encode($this->service->export([self::SOURCE]));

        // Real money and real player data. Moving a claim between environments would be moving
        // somebody's purchase.
        foreach (['plot_claim', 'claimedBy', 'userLedger', 'holds', 'sellBack'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "export must not carry $forbidden");
        }
    }

    public function testExportCanBeScopedToOneCountry(): void
    {
        $this->country(self::SOURCE);
        $this->country(self::TARGET);
        $this->location(self::SOURCE, 'Alpha');
        $this->location(self::TARGET, 'Beta');

        $data = $this->service->export([self::SOURCE]);

        $this->assertCount(1, $data['countries']);
        $this->assertCount(1, $data['locations']);
        $this->assertSame(self::SOURCE, $data['locations'][0]['countryCode']);
    }

    // ───────────────────────── import ─────────────────────────

    public function testImportIntoAnEmptyEnvironmentCreatesEverything(): void
    {
        $this->country(self::SOURCE);
        $this->location(self::SOURCE, 'Alpha', enriched: true);

        $data = $this->service->export([self::SOURCE]);

        // Simulate a different environment: same slug, different country code, nothing present.
        $data = $this->retarget($data, self::TARGET);
        $this->em->clear();

        $result = $this->service->import($data);

        $this->assertTrue($result['applied']);
        $this->assertSame(1, $result['countriesCreated']);
        $this->assertSame(1, $result['locationsCreated']);
        $this->assertSame(1, $result['plotsCreated'], 'a town with no plot cannot be bought');

        $imported = $this->locations->findBySlug(self::TARGET, strtolower(self::TARGET . '-alpha'));
        $this->assertNotNull($imported);
        $this->assertSame(EnrichmentStatus::ENRICHED, $imported->getEnrichmentStatus());
        $this->assertSame(1_234_00, $imported->getLandPence());
    }

    public function testReimportingIsAnUpsertNotADuplicate(): void
    {
        $this->country(self::SOURCE);
        $this->location(self::SOURCE, 'Alpha', enriched: true);

        $data = $this->service->export([self::SOURCE]);

        $this->service->import($data);
        $this->service->import($data);
        $this->em->clear();

        // Matched on (countryCode, slug), so the second run updates rather than inserting.
        $this->assertCount(1, $this->locations->findByCountry(self::SOURCE));
    }

    public function testAnExistingRowKeepsItsOwnIdSoClaimsStayPointedAtIt(): void
    {
        $this->country(self::SOURCE);
        $location = $this->location(self::SOURCE, 'Alpha');
        $originalId = (string) $location->getId();

        $data = $this->service->export([self::SOURCE]);
        $this->em->clear();

        $this->service->import($data);
        $this->em->clear();

        $reloaded = $this->locations->findBySlug(self::SOURCE, strtolower(self::SOURCE . '-alpha'));

        // Re-keying an existing town would orphan every claim and hold against it.
        $this->assertSame($originalId, (string) $reloaded->getId());
    }

    public function testADryRunReportsTheCountsAndWritesNothing(): void
    {
        $this->country(self::SOURCE);
        $this->location(self::SOURCE, 'Alpha', enriched: true);

        $data = $this->retarget($this->service->export([self::SOURCE]), self::TARGET);
        $this->em->clear();

        $result = $this->service->import($data, dryRun: true);

        $this->assertFalse($result['applied']);
        $this->assertSame(1, $result['locationsCreated'], 'the counts are what a real run would do');
        $this->assertSame([], $this->locations->findByCountry(self::TARGET), 'and nothing was written');
    }

    // ───────────────────────── the locks ─────────────────────────

    public function testALockedLandValueInTheTargetIsNotOverwritten(): void
    {
        $this->country(self::SOURCE);
        $this->location(self::SOURCE, 'Alpha', enriched: true);

        $data = $this->service->export([self::SOURCE]);

        // An admin in THIS environment has corrected and locked the valuation.
        $target = $this->locations->findBySlug(self::SOURCE, strtolower(self::SOURCE . '-alpha'));
        $target->setLandPence(99_999_00);
        $target->setIsLandLocked(true);
        $this->em->flush();
        $this->em->clear();

        $result = $this->service->import($data);
        $this->em->clear();

        $reloaded = $this->locations->findBySlug(self::SOURCE, strtolower(self::SOURCE . '-alpha'));

        // THE ASSERTION THIS FILE EXISTS FOR. Importing geography must not undo a human review.
        $this->assertSame(99_999_00, $reloaded->getLandPence(), 'the human correction survives');
        $this->assertGreaterThan(0, $result['lockedFieldsSkipped'], 'and the skip is reported');
    }

    public function testALockedCoordinateInTheTargetIsNotOverwritten(): void
    {
        $this->country(self::SOURCE);
        $this->location(self::SOURCE, 'Alpha', enriched: true);

        $data   = $this->service->export([self::SOURCE]);
        $target = $this->locations->findBySlug(self::SOURCE, strtolower(self::SOURCE . '-alpha'));
        $target->setCoordinates(1.5, 2.5);
        $target->setIsCoordinatesLocked(true);
        $this->em->flush();
        $this->em->clear();

        $this->service->import($data);
        $this->em->clear();

        $reloaded = $this->locations->findBySlug(self::SOURCE, strtolower(self::SOURCE . '-alpha'));

        $this->assertSame(1.5, $reloaded->getLat());
        $this->assertSame(2.5, $reloaded->getLng());
    }

    public function testLocksTravelWithTheDataSoAReviewedTownStaysProtected(): void
    {
        $this->country(self::SOURCE);
        $source = $this->location(self::SOURCE, 'Alpha', enriched: true);
        $source->setIsLandLocked(true);
        $this->em->flush();

        $data = $this->retarget($this->service->export([self::SOURCE]), self::TARGET);
        $this->em->clear();

        $this->service->import($data);
        $this->em->clear();

        $imported = $this->locations->findBySlug(self::TARGET, strtolower(self::TARGET . '-alpha'));

        // The value lands AND is protected, so the next sweep in the target cannot undo the
        // review that was done in the source.
        $this->assertSame(1_234_00, $imported->getLandPence());
        $this->assertTrue($imported->isLandLocked());
    }

    // ───────────────────────── runtime state ─────────────────────────

    public function testAnImportedRowCarriesNoEnrichmentLease(): void
    {
        $this->country(self::SOURCE);
        $location = $this->location(self::SOURCE, 'Alpha', enriched: true);
        $location->setEnrichmentClaimedAt(new \DateTimeImmutable());
        $this->em->flush();

        $data = $this->retarget($this->service->export([self::SOURCE]), self::TARGET);
        $this->em->clear();

        $this->service->import($data);
        $this->em->clear();

        $imported = $this->locations->findBySlug(self::TARGET, strtolower(self::TARGET . '-alpha'));

        // A lease belongs to the environment whose worker took it. Importing one makes a town
        // look claimed-for-work by a worker that does not exist here.
        $this->assertNull($imported->getEnrichmentClaimedAt());
    }

    public function testImportInvalidatesRivalsSoTheyGetRecomputed(): void
    {
        $this->country(self::SOURCE);
        $location = $this->location(self::SOURCE, 'Alpha', enriched: true);
        $location->setRivalsComputedAt(new \DateTimeImmutable());
        $this->em->flush();

        $data = $this->service->export([self::SOURCE]);
        $this->em->clear();

        $this->service->import($data);
        $this->em->clear();

        $reloaded = $this->locations->findBySlug(self::SOURCE, strtolower(self::SOURCE . '-alpha'));

        // Rivals derive from coordinates that may have just changed; nulling this is what makes
        // `app:locations:rivals --stale-only` pick the row up.
        $this->assertNull($reloaded->getRivalsComputedAt());
    }

    // ───────────────────────── malformed input ─────────────────────────

    public function testAWrongVersionIsRefusedOutright(): void
    {
        $result = $this->service->import(['version' => 99, 'countries' => [], 'locations' => []]);

        $this->assertFalse($result['applied']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testOneBadRowDoesNotDiscardTheGoodOnes(): void
    {
        $this->country(self::SOURCE);
        $this->location(self::SOURCE, 'Alpha');
        $this->location(self::SOURCE, 'Beta');

        $data = $this->retarget($this->service->export([self::SOURCE]), self::TARGET);

        // A hand-edited file with one town missing its slug.
        unset($data['locations'][0]['slug']);
        $this->em->clear();

        $result = $this->service->import($data);

        $this->assertTrue($result['applied']);
        $this->assertCount(1, $result['errors'], 'the bad row is reported');
        $this->assertSame(1, $result['locationsCreated'], 'and the good one still lands');
    }

    public function testALocationWhoseCountryIsMissingFromTheFileIsReported(): void
    {
        $this->country(self::SOURCE);
        $this->location(self::SOURCE, 'Alpha');

        $data = $this->retarget($this->service->export([self::SOURCE]), self::TARGET);
        $data['countries'] = [];
        $this->em->clear();

        $result = $this->service->import($data);

        // country_code is a RESTRICT FK, so this cannot be inserted — far better as a reported
        // error than as a foreign-key violation that closes the EntityManager.
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('no country profile', $result['errors'][0]);
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function retarget(array $data, string $code): array
    {
        foreach ($data['countries'] as &$country) {
            $country['code'] = $code;
        }
        unset($country);

        foreach ($data['locations'] as &$location) {
            $location['countryCode'] = $code;
            $location['slug']        = strtolower($code . '-' . explode('-', $location['slug'], 2)[1]);
        }
        unset($location);

        return $data;
    }

    /** @return array<string, mixed> */
    private function exportOne(string $code): array
    {
        return $this->service->export([$code])['locations'][0];
    }

    private function country(string $code): CountryProfile
    {
        $country = new CountryProfile($code, 'Land ' . $code);
        $country->setStatus(CountryProfileStatus::LIVE);

        $this->em->persist($country);
        $this->em->flush();

        return $country;
    }

    private function location(string $code, string $town, bool $enriched = false): Location
    {
        $country  = $this->em->find(CountryProfile::class, $code);
        $slug     = strtolower($code . '-' . $town);
        $location = new Location($country, $town, $slug, strtolower($town));

        $location->setRegion('R');
        $location->setPopulation(120_000);
        $location->setCoordinates(53.5, -2.5);
        $location->setLandPence(1_234_00);
        $location->setLandValuedAt(new \DateTimeImmutable());

        if ($enriched) {
            $location->setEnrichmentStatus(EnrichmentStatus::ENRICHED);
            $location->setEnrichedAt(new \DateTimeImmutable());
            $location->setEnrichmentProvider('anthropic');
            $location->setEnrichmentModel('claude-sonnet-5');
        }

        $this->em->persist($location);
        $this->em->persist(new LandPlot($location, 1));
        $this->em->flush();

        return $location;
    }

    private function purge(): void
    {
        $in = "('" . self::SOURCE . "','" . self::TARGET . "')";

        $this->connection->executeStatement("DELETE FROM location_rival WHERE location_id IN (SELECT id FROM location WHERE country_code IN $in)");
        $this->connection->executeStatement("DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code IN $in)");
        $this->connection->executeStatement("DELETE FROM location WHERE country_code IN $in");
        $this->connection->executeStatement("DELETE FROM country_profile WHERE code IN $in");
    }
}
