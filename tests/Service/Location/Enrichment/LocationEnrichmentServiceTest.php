<?php

declare(strict_types=1);

namespace App\Tests\Service\Location\Enrichment;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\Location;
use App\Entity\Location\LocationEnrichmentAttempt;
use App\Enum\Location\EnrichmentOutcome;
use App\Enum\Location\EnrichmentStatus;
use App\Message\Location\EnrichLocationMessage;
use App\Repository\Location\LocationEnrichmentAttemptRepository;
use App\Repository\Location\LocationRepository;
use App\Service\Location\Enrichment\EnrichmentResult;
use App\Service\Location\Enrichment\EnrichmentUnavailableException;
use App\Service\Location\Enrichment\EnrichmentValidator;
use App\Service\Location\Enrichment\LocationEnrichmentService;
use App\Tests\Support\FakeLocationEnricher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The lease, the dispatch, and the write-back — against the real database.
 *
 * The two guarantees worth testing are both about money and both invisible in a unit test of
 * any single method: one paid API call per location however many people look at it, and an
 * admin's hand correction never being undone by a later run.
 */
class LocationEnrichmentServiceTest extends KernelTestCase
{
    private const COUNTRY = 'ZY';

    private EntityManagerInterface $em;
    private Connection $connection;
    private LocationRepository $locations;
    private FakeLocationEnricher $enricher;
    private CountryProfile $country;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->locations  = self::getContainer()->get(LocationRepository::class);
        $this->enricher   = new FakeLocationEnricher();

        $this->purge();

        $this->country = new CountryProfile(self::COUNTRY, 'Testland');
        // A box around the coordinates the fixtures use, so in-box and out-of-box are both
        // expressible.
        $this->country->setBbox(['minLat' => 50.0, 'maxLat' => 56.0, 'minLng' => -6.0, 'maxLng' => 2.0]);
        $this->country->setLandPenceMin(1_000_00);
        $this->country->setLandPenceMax(100_000_00);
        $this->em->persist($this->country);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM location_enrichment_attempt WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            ['c' => self::COUNTRY],
        );
        $this->connection->executeStatement(
            'DELETE FROM location_rival WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            ['c' => self::COUNTRY],
        );
        $this->connection->executeStatement(
            'DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            ['c' => self::COUNTRY],
        );
        $this->connection->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::COUNTRY]);
        $this->connection->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::COUNTRY]);
    }

    private function location(string $town = 'Testville', ?int $population = null): Location
    {
        // Re-resolved rather than reused: several tests call em->clear() to simulate a fresh
        // request, which detaches the shared CountryProfile.
        $country = $this->em->find(CountryProfile::class, self::COUNTRY);

        $location = new Location($country, $town, strtolower(self::COUNTRY . '-' . $town), strtolower($town));
        $location->setRegion('Test Region');

        if ($population !== null) {
            $location->setPopulation($population);
        }

        $this->em->persist($location);
        $this->em->flush();

        return $location;
    }

    private function service(): LocationEnrichmentService
    {
        return new LocationEnrichmentService(
            $this->locations,
            self::getContainer()->get(LocationEnrichmentAttemptRepository::class),
            self::getContainer()->get(\App\Repository\Location\LocationEnrichmentRunRepository::class),
            $this->enricher,
            new EnrichmentValidator(),
            self::getContainer()->get(MessageBusInterface::class),
            $this->em,
            new NullLogger(),
        );
    }

    private function transport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.enrichment');

        return $transport;
    }

    private function statusOf(Location $location): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT enrichment_status FROM location WHERE id = :id',
            ['id' => (string) $location->getId()],
        );
    }

    // ───────────────── the thundering-herd guarantee ─────────────────

    /**
     * THE test for this feature's cost. Many readers, one dispatch.
     *
     * Each call is a separate reader arriving at the same un-enriched town. Only the one whose
     * conditional UPDATE affected a row may dispatch, so the others are no-ops — which is the
     * difference between one paid API call and fifty.
     */
    public function testManyReadersProduceExactlyOneDispatch(): void
    {
        $location = $this->location();
        $service  = $this->service();

        $this->transport()->reset();

        $won = 0;
        for ($i = 0; $i < 25; $i++) {
            // Re-read so each "reader" sees the row as the database now has it, the way a
            // separate request would.
            $this->em->clear();
            $fresh = $this->locations->find((string) $location->getId());

            if ($service->queueForRead($fresh)) {
                ++$won;
            }
        }

        self::assertSame(1, $won, '25 readers should produce exactly one claim');
        self::assertCount(1, $this->transport()->getSent(), 'and exactly one dispatched message');

        $envelope = $this->transport()->getSent()[0];
        self::assertInstanceOf(EnrichLocationMessage::class, $envelope->getMessage());
        self::assertSame(EnrichmentStatus::QUEUED->value, $this->statusOf($location));
    }

    /** A stuck QUEUED row is reclaimable once its lease expires — a worker died mid-call. */
    public function testStuckQueuedRowIsReclaimableAfterItsLease(): void
    {
        $location = $this->location();
        $service  = $this->service();

        self::assertTrue($service->queueForRead($location));

        // Backdate the claim past the lease, as a dead worker would leave it.
        $this->connection->executeStatement(
            'UPDATE location SET enrichment_claimed_at = :then WHERE id = :id',
            [
                'then' => (new \DateTimeImmutable())->modify('-' . (Location::CLAIM_LEASE_SECONDS + 60) . ' seconds'),
                'id'   => (string) $location->getId(),
            ],
            ['then' => 'datetime_immutable'],
        );

        $this->em->clear();
        $fresh = $this->locations->find((string) $location->getId());

        self::assertTrue(
            $this->locations->claimForEnrichment($fresh, new \DateTimeImmutable()),
            'an expired lease must be reclaimable, or a dead worker pins the row for ever',
        );
    }

    /**
     * A town that cannot be resolved must not burn an API call on every page view.
     *
     * The lazy path refuses a row at the attempt cap; only an explicit admin retry gets past
     * it.
     */
    public function testLazyPathRefusesARowAtTheAttemptCap(): void
    {
        $location = $this->location();

        $this->connection->executeStatement(
            'UPDATE location SET enrichment_attempts = :n WHERE id = :id',
            ['n' => Location::MAX_ENRICHMENT_ATTEMPTS, 'id' => (string) $location->getId()],
        );

        $this->em->clear();
        $fresh = $this->locations->find((string) $location->getId());

        $this->transport()->reset();

        self::assertFalse($this->service()->queueForRead($fresh));
        self::assertCount(0, $this->transport()->getSent());
    }

    /** A review-flagged or failed row is not re-queued by a reader either. */
    public function testLazyPathOnlyClaimsPendingRows(): void
    {
        foreach ([EnrichmentStatus::NEEDS_REVIEW, EnrichmentStatus::FAILED, EnrichmentStatus::ENRICHED] as $status) {
            $location = $this->location('Town' . $status->value);

            $this->connection->executeStatement(
                'UPDATE location SET enrichment_status = :s WHERE id = :id',
                ['s' => $status->value, 'id' => (string) $location->getId()],
            );

            $this->em->clear();
            $fresh = $this->locations->find((string) $location->getId());

            self::assertFalse(
                $this->service()->queueForRead($fresh),
                "{$status->value} must not be re-queued by a reader",
            );
        }
    }

    // ───────────────── write-back ─────────────────

    public function testAcceptedResultIsWrittenWithProvenance(): void
    {
        $location = $this->location(population: 270_000);

        $this->enricher->always(new EnrichmentResult(
            lat: 53.4808,
            lng: -2.2426,
            landPence: 45_000_00,
            raw: ['lat' => 53.4808, 'lng' => -2.2426],
            inputTokens: 410,
            outputTokens: 190,
        ));

        $attempt = $this->service()->enrich($location);

        self::assertSame(EnrichmentOutcome::ACCEPTED, $attempt->getOutcome());
        self::assertSame(EnrichmentStatus::ENRICHED->value, $this->statusOf($location));

        $row = $this->connection->fetchAssociative(
            'SELECT lat, lng, land_pence, land_source, enrichment_provider, enrichment_model, enrichment_attempts
               FROM location WHERE id = :id',
            ['id' => (string) $location->getId()],
        );

        self::assertEqualsWithDelta(53.4808, (float) $row['lat'], 0.0001);
        self::assertSame(4500000, (int) $row['land_pence']);
        self::assertStringContainsString('fake:fake-1:', (string) $row['land_source']);
        self::assertSame('fake', $row['enrichment_provider']);
        self::assertSame(1, (int) $row['enrichment_attempts']);

        self::assertSame(410, $attempt->getInputTokens());
        self::assertSame(190, $attempt->getOutputTokens());
    }

    /** A partial answer keeps the good half and flags the row rather than failing it. */
    public function testPartialResultLandsNeedsReview(): void
    {
        $location = $this->location(population: 270_000);

        $this->enricher->always(new EnrichmentResult(
            lat: 53.4808,
            lng: -2.2426,
            landPence: 9_999_999_999,
            raw: ['note' => 'absurd valuation'],
        ));

        $attempt = $this->service()->enrich($location);

        self::assertSame(EnrichmentOutcome::PARTIAL, $attempt->getOutcome());
        self::assertSame(EnrichmentStatus::NEEDS_REVIEW->value, $this->statusOf($location));

        // The coordinates survived; the valuation did not.
        $row = $this->connection->fetchAssociative(
            'SELECT lat, land_pence FROM location WHERE id = :id',
            ['id' => (string) $location->getId()],
        );

        self::assertNotNull($row['lat']);
        self::assertNull($row['land_pence']);
        self::assertArrayHasKey('landPence', $attempt->getRejectedFields());
    }

    /**
     * An admin's correction survives a later enrichment, always.
     *
     * This is the other guarantee the service exists for. A re-run that overwrote a hand-fixed
     * coordinate would make the admin screens pointless.
     */
    public function testLockedFieldsAreNeverOverwritten(): void
    {
        $location = $this->location();
        $location->setCoordinates(51.5074, -0.1278);
        $location->setIsCoordinatesLocked(true);
        $location->setLandPence(12_345_00);
        $location->setIsLandLocked(true);
        $this->em->flush();

        $this->enricher->always(new EnrichmentResult(lat: 53.4808, lng: -2.2426, landPence: 99_000_00));

        $this->service()->enrich($location);

        $row = $this->connection->fetchAssociative(
            'SELECT lat, land_pence FROM location WHERE id = :id',
            ['id' => (string) $location->getId()],
        );

        self::assertEqualsWithDelta(51.5074, (float) $row['lat'], 0.0001, 'a locked coordinate was overwritten');
        self::assertSame(1234500, (int) $row['land_pence'], 'a locked land value was overwritten');
    }

    /**
     * A provider failure must leave a trace — this is the Firebase lesson, encoded.
     *
     * A missing FIREBASE_SERVICE_ACCOUNT_JSON once discarded every push notification in this
     * codebase with no record at all. An unconfigured or failing enricher must write an
     * attempt row BEFORE the exception escapes, or an 865-row sweep fails silently.
     */
    public function testProviderFailureWritesAnAttemptRowBeforeRethrowing(): void
    {
        $location = $this->location();
        $this->enricher->always(new EnrichmentUnavailableException('no API key configured'));

        try {
            $this->service()->enrich($location);
            self::fail('the provider exception should have been rethrown');
        } catch (EnrichmentUnavailableException $e) {
            self::assertStringContainsString('no API key', $e->getMessage());
        }

        $this->em->clear();

        $attempts = self::getContainer()
            ->get(LocationEnrichmentAttemptRepository::class)
            ->findForLocation($this->locations->find((string) $location->getId()));

        self::assertCount(1, $attempts, 'the failure left no trace');
        self::assertSame(EnrichmentOutcome::ERROR, $attempts[0]->getOutcome());
        self::assertStringContainsString('no API key', (string) $attempts[0]->getErrorMessage());

        self::assertSame(EnrichmentStatus::FAILED->value, $this->statusOf($location));
    }

    /** Enriching one town invalidates its neighbours' rival sets, not just its own. */
    public function testNewCoordinatesInvalidateNeighbouringRivalSets(): void
    {
        $neighbour = $this->location('Neighbourton');
        $neighbour->setCoordinates(53.4800, -2.2400);
        $neighbour->setRivalsComputedAt(new \DateTimeImmutable());
        $this->em->flush();

        self::assertNotNull($neighbour->getRivalsComputedAt());

        $subject = $this->location('Subjectville');
        $this->enricher->always(new EnrichmentResult(lat: 53.4808, lng: -2.2426));

        $this->service()->enrich($subject);

        $stale = $this->connection->fetchOne(
            'SELECT rivals_computed_at FROM location WHERE id = :id',
            ['id' => (string) $neighbour->getId()],
        );

        self::assertNull($stale, "a newly placed town must stale its neighbours' rival sets");
    }

    /** beginWork() is the handler's guard: gone, or already settled, means do nothing. */
    public function testBeginWorkReturnsNullForAMissingOrSettledLocation(): void
    {
        $service = $this->service();

        self::assertNull($service->beginWork('0192f000-0000-7000-8000-000000000000'));

        $location = $this->location();
        self::assertNull(
            $service->beginWork((string) $location->getId()),
            'a PENDING row was never queued, so there is no work to begin',
        );
    }

    /** The handler's lease refresh is what stops a queue backlog expiring its own claim. */
    public function testBeginWorkRefreshesTheLease(): void
    {
        $location = $this->location();
        $service  = $this->service();

        self::assertTrue($service->queueForRead($location));

        $stale = (new \DateTimeImmutable())->modify('-200 seconds');
        $this->connection->executeStatement(
            'UPDATE location SET enrichment_claimed_at = :then WHERE id = :id',
            ['then' => $stale, 'id' => (string) $location->getId()],
            ['then' => 'datetime_immutable'],
        );

        $this->em->clear();

        self::assertNotNull($service->beginWork((string) $location->getId()));

        $refreshed = (string) $this->connection->fetchOne(
            'SELECT enrichment_claimed_at FROM location WHERE id = :id',
            ['id' => (string) $location->getId()],
        );

        self::assertGreaterThan(
            $stale->format('Y-m-d H:i:s'),
            $refreshed,
            'beginWork() must re-stamp the lease so a backlog does not expire it',
        );
    }
}
