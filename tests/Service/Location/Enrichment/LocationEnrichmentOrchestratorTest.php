<?php

declare(strict_types=1);

namespace App\Tests\Service\Location\Enrichment;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\Location;
use App\Enum\Location\EnrichmentStatus;
use App\Enum\Location\EnrichmentTrigger;
use App\Message\Location\EnrichLocationMessage;
use App\Repository\Location\LocationRepository;
use App\Service\Location\Enrichment\LocationEnrichmentOrchestrator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The three admin triggers, and the guard that stops a sweep being paid for twice.
 *
 * Every message on the enrichment queue is a billed API call, which makes this the one
 * orchestrator in the codebase where "queued it twice by accident" costs money rather than
 * just time.
 */
class LocationEnrichmentOrchestratorTest extends KernelTestCase
{
    private const COUNTRY = 'ZX';
    private const OTHER   = 'ZW';

    private EntityManagerInterface $em;
    private Connection $connection;
    private LocationEnrichmentOrchestrator $orchestrator;
    private LocationRepository $locations;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em           = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection   = self::getContainer()->get(Connection::class);
        $this->orchestrator = self::getContainer()->get(LocationEnrichmentOrchestrator::class);
        $this->locations    = self::getContainer()->get(LocationRepository::class);

        $this->purge();

        foreach ([self::COUNTRY, self::OTHER] as $code) {
            $this->em->persist(new CountryProfile($code, "Testland {$code}"));
        }
        $this->em->flush();

        $this->transport()->reset();

        // Asserted, not assumed. These tests share one database and one in-memory transport,
        // and an earlier run of this file failed once with a count that only makes sense if
        // rows had leaked in from a previous test. I could not reproduce it, so instead of
        // explaining it away the precondition is now checked: any future leak fails here, in
        // setUp, naming the problem, rather than somewhere downstream looking like a bug in
        // the code under test.
        self::assertSame(
            0,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM location WHERE country_code IN (:c)',
                ['c' => [self::COUNTRY, self::OTHER]],
                ['c' => \Doctrine\DBAL\ArrayParameterType::STRING],
            ),
            'test fixtures leaked from a previous test — purge() did not do its job',
        );
        self::assertCount(0, $this->transport()->getSent());
    }

    protected function tearDown(): void
    {
        $this->purge();

        parent::tearDown();
    }

    private function purge(): void
    {
        foreach ([self::COUNTRY, self::OTHER] as $code) {
            $this->connection->executeStatement(
                'DELETE FROM location_enrichment_attempt WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
                ['c' => $code],
            );
            $this->connection->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => $code]);
            $this->connection->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => $code]);
        }

        $this->connection->executeStatement("DELETE FROM location_enrichment_run WHERE country_code IN (:c)", ['c' => [self::COUNTRY, self::OTHER]], ['c' => \Doctrine\DBAL\ArrayParameterType::STRING]);
    }

    private function transport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.enrichment');
    }

    /** @return list<Location> */
    private function locations(string $code, int $count, EnrichmentStatus $status = EnrichmentStatus::PENDING): array
    {
        $country = $this->em->find(CountryProfile::class, $code);
        $out     = [];

        for ($i = 0; $i < $count; $i++) {
            $location = new Location($country, "Town{$i}", strtolower("{$code}-town{$i}"), "town{$i}");
            $location->setRegion('R');
            $location->setEnrichmentStatus($status);
            $this->em->persist($location);
            $out[] = $location;
        }

        $this->em->flush();

        return $out;
    }

    public function testQueueAllOutstandingClaimsAndDispatchesOnePerLocation(): void
    {
        $this->locations(self::COUNTRY, 5);

        $run = $this->orchestrator->queueAllOutstanding(self::COUNTRY);

        self::assertNotNull($run);
        self::assertSame(5, $run->getQueued());
        self::assertSame(EnrichmentTrigger::ADMIN_ALL, $run->getTrigger());
        self::assertCount(5, $this->transport()->getSent());

        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(EnrichLocationMessage::class, $message);
            // Every message carries its run, so counters can be advanced without a lookup table.
            self::assertSame((string) $run->getId(), $message->runId);
        }

        // And every claimed row is now QUEUED, so a reader cannot queue it again.
        $pending = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM location WHERE country_code = :c AND enrichment_status = 'pending'",
            ['c' => self::COUNTRY],
        );
        self::assertSame(0, $pending);
    }

    /** A sweep is scoped to its country — the mistake that is expensive at 25 countries. */
    public function testSweepDoesNotTouchOtherCountries(): void
    {
        $this->locations(self::COUNTRY, 3);
        $this->locations(self::OTHER, 4);

        $run = $this->orchestrator->queueAllOutstanding(self::COUNTRY);

        self::assertNotNull($run);
        self::assertSame(3, $run->getQueued());

        $otherStillPending = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM location WHERE country_code = :c AND enrichment_status = 'pending'",
            ['c' => self::OTHER],
        );
        self::assertSame(4, $otherStillPending);
    }

    /**
     * The limit must bind. A sweep that ignores it bills for every row in the country.
     *
     * This caught a real bug, and only when run inside a full suite: `LIMIT :limit` bound
     * alongside an ArrayParameterType placeholder came through as NULL, and PostgreSQL reads
     * `LIMIT NULL` as `LIMIT ALL` — so asking for 4 claimed all 10, silently, on the one path
     * in this feature where each claimed row costs money. Both layers are asserted because the
     * repository is where the bug was and the orchestrator is what operators actually call.
     */
    public function testLimitIsHonoured(): void
    {
        $this->locations(self::COUNTRY, 10);

        // The repository, directly — where the NULL-bound limit went wrong.
        $claimed = $this->locations->claimBatchForEnrichment(
            [EnrichmentStatus::PENDING],
            new \DateTimeImmutable(),
            4,
            self::COUNTRY,
        );

        self::assertCount(4, $claimed, 'claimBatchForEnrichment ignored its limit');

        // And through the orchestrator, on the six rows still pending.
        $run = $this->orchestrator->queueAllOutstanding(self::COUNTRY, null, null, 3);

        self::assertNotNull($run);
        self::assertSame(3, $run->getQueued());
        self::assertCount(3, $this->transport()->getSent());
    }

    /** An already-enriched row is never re-swept. */
    public function testEnrichedRowsAreNotReSwept(): void
    {
        $this->locations(self::COUNTRY, 3, EnrichmentStatus::ENRICHED);

        self::assertSame(0, $this->orchestrator->countOutstanding(self::COUNTRY));
        self::assertNull($this->orchestrator->queueAllOutstanding(self::COUNTRY));
        self::assertCount(0, $this->transport()->getSent());
    }

    /** FAILED rows are swept; that is how a transient provider outage is retried in bulk. */
    public function testFailedRowsAreSwept(): void
    {
        $this->locations(self::COUNTRY, 2, EnrichmentStatus::FAILED);

        self::assertSame(2, $this->orchestrator->countOutstanding(self::COUNTRY));

        $run = $this->orchestrator->queueAllOutstanding(self::COUNTRY);

        self::assertNotNull($run);
        self::assertSame(2, $run->getQueued());
    }

    /**
     * NEEDS_REVIEW is not swept by default.
     *
     * A row a human has not yet looked at should not be silently re-asked — the flag exists to
     * get attention, and re-rolling it would hide the thing that needs deciding.
     */
    public function testNeedsReviewIsNotSweptUnlessAskedFor(): void
    {
        $this->locations(self::COUNTRY, 3, EnrichmentStatus::NEEDS_REVIEW);

        self::assertSame(0, $this->orchestrator->countOutstanding(self::COUNTRY));

        $run = $this->orchestrator->queueAllOutstanding(
            self::COUNTRY,
            null,
            [EnrichmentStatus::NEEDS_REVIEW],
        );

        self::assertNotNull($run, 'asked for explicitly, it should sweep');
        self::assertSame(3, $run->getQueued());
    }

    /** An admin retrying one row gets past the review flag and the attempt cap. */
    public function testQueueSingleClaimsAReviewFlaggedRow(): void
    {
        [$location] = $this->locations(self::COUNTRY, 1, EnrichmentStatus::NEEDS_REVIEW);

        $this->connection->executeStatement(
            'UPDATE location SET enrichment_attempts = :n WHERE id = :id',
            ['n' => Location::MAX_ENRICHMENT_ATTEMPTS + 2, 'id' => (string) $location->getId()],
        );

        $run = $this->orchestrator->queueSingle((string) $location->getId());

        self::assertNotNull($run, 'an explicit admin retry is the documented way past the cap');
        self::assertSame(1, $run->getQueued());
        self::assertSame(EnrichmentTrigger::ADMIN_SINGLE, $run->getTrigger());
    }

    public function testQueueSubsetClaimsOnlyTheNamedRows(): void
    {
        $all = $this->locations(self::COUNTRY, 5);
        $ids = [(string) $all[0]->getId(), (string) $all[3]->getId()];

        $run = $this->orchestrator->queueSubset($ids);

        self::assertNotNull($run);
        self::assertSame(2, $run->getQueued());
        self::assertCount(2, $this->transport()->getSent());

        $queued = $this->connection->fetchFirstColumn(
            "SELECT id FROM location WHERE country_code = :c AND enrichment_status = 'queued'",
            ['c' => self::COUNTRY],
        );

        self::assertEqualsCanonicalizing($ids, array_map('strval', $queued));
    }

    /** A row already in flight is not claimed a second time, so it is not paid for twice. */
    public function testASubsetWillNotReClaimAnAlreadyQueuedRow(): void
    {
        [$location] = $this->locations(self::COUNTRY, 1);

        self::assertNotNull($this->orchestrator->queueSingle((string) $location->getId()));
        $this->em->clear();

        self::assertNull(
            $this->orchestrator->queueSubset([(string) $location->getId()]),
            'a QUEUED row must not be re-claimed',
        );
    }

    public function testQueueSubsetWithNoIdsDoesNothing(): void
    {
        self::assertNull($this->orchestrator->queueSubset([]));
        self::assertCount(0, $this->transport()->getSent());
    }

    public function testCountOutstandingMatchesWhatASweepWouldClaim(): void
    {
        $this->locations(self::COUNTRY, 4);

        $predicted = $this->orchestrator->countOutstanding(self::COUNTRY);
        $run       = $this->orchestrator->queueAllOutstanding(self::COUNTRY);

        self::assertNotNull($run);
        self::assertSame(
            $predicted,
            $run->getQueued(),
            'the number shown to an operator must be the number the real call acts on',
        );
    }
}
