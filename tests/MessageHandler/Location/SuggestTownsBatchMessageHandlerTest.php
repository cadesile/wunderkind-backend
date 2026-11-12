<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Location;

use App\Enum\Country;
use App\Enum\Location\EnrichmentRunStatus;
use App\Enum\Location\TownSuggestionBatchStatus;
use App\Message\Location\SuggestTownsBatchMessage;
use App\MessageHandler\Location\SuggestTownsBatchMessageHandler;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\Location\LocationEnrichmentRunRepository;
use App\Repository\Location\TownSuggestionBatchRepository;
use App\Service\Location\LocationSeedService;
use App\Service\Location\Suggestion\TownSuggestionService;
use App\Service\Location\Suggestion\TownSuggestionUnavailableException;
use App\Service\Location\Suggestion\TownSuggestionValidator;
use App\Tests\Support\FakeTownSuggester;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * One batch of a queued town pull, and above all what happens when it goes wrong.
 *
 * The failure behaviour is the point of this file. Retry is self-managed on the batch row
 * rather than left to Messenger, so a run being watched by a live progress bar tells one
 * coherent story instead of a batch flashing "failed" and then un-failing itself when an
 * invisible retry succeeds.
 *
 * Driven through the REAL service with a fake suggester rather than a mocked one.
 * TownSuggestionService is final and should stay that way, and the branch under test is
 * entirely about what the handler does when runBatch() throws — so making the provider throw
 * exercises the true path instead of a stand-in for it.
 */
class SuggestTownsBatchMessageHandlerTest extends KernelTestCase
{
    private const COUNTRY = Country::TN;

    private EntityManagerInterface $em;
    private Connection $connection;
    private LocationSeedService $seedService;
    private FakeTownSuggester $suggester;
    private TownSuggestionBatchRepository $batchRepository;
    private LocationEnrichmentRunRepository $runRepository;

    /** @var object{dispatched: list<object>, stamps: list<array>} */
    private object $bus;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em              = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection      = self::getContainer()->get(Connection::class);
        $this->seedService     = self::getContainer()->get(LocationSeedService::class);
        $this->batchRepository = self::getContainer()->get(TownSuggestionBatchRepository::class);
        $this->runRepository   = self::getContainer()->get(LocationEnrichmentRunRepository::class);
        $this->suggester       = new FakeTownSuggester();
        $this->bus             = $this->spyBus();

        $this->purge();
        $this->seedService->seedCountry(self::COUNTRY);
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testASuccessfulBatchIsRecordedAndCountedAgainstItsRun(): void
    {
        $plan    = $this->service()->planRun(self::COUNTRY, TownSuggestionService::BATCH_SIZE);
        $batchId = (string) $plan['batches'][0]->getId();
        $runId   = (string) $plan['run']->getId();

        $this->suggester->queueNames(['Handlerville', 'Dispatchtown']);
        $this->handle($batchId);

        $batch = $this->freshBatch($batchId);
        self::assertSame(TownSuggestionBatchStatus::COMPLETED, $batch->getStatus());
        self::assertSame(2, $batch->getImported());
        self::assertSame(1, $batch->getAttempts());
        self::assertNotNull($batch->getFinishedAt());
        self::assertNull($batch->getErrorMessage());

        // A single-batch run, so recording it also closes the run.
        $run = $this->runRepository->find($runId);
        self::assertSame(EnrichmentRunStatus::COMPLETE, $run->getStatus());
        self::assertSame(1, $run->getSucceeded());

        self::assertSame([], $this->bus->dispatched, 'a batch that worked must not re-queue itself');
    }

    /**
     * A country the curated registry has never heard of gets its batches RUN, not just queued.
     *
     * THIS IS THE BUG THIS CASE EXISTS FOR. The planning side was taught to accept any country
     * and the handler was not, so a pull for Ghana queued twelve batches happily and then
     * failed every one of them three times over with "'GH' has no entry in
     * CountryContentRegistry::PLACE_NAMES_BY_COUNTRY, so there is nothing to seed" — an error
     * about SEEDING, raised by the one feature whose entire purpose is to serve a country that
     * cannot be seeded.
     *
     * Planning and running have to agree about which countries they accept. Asserting on the
     * planning side alone is what let them drift.
     */
    public function testABatchRunsForACountryWithNoCuratedPlaceList(): void
    {
        $country = \App\Enum\Country::GH;

        self::assertNull(
            \App\Service\CountryContent\CountryContentRegistry::places($country),
            'fixture check: this country must have no curated place list',
        );

        $this->purgeCountry($country->value);
        $this->seedService->ensureCountryProfile($country);

        $plan    = $this->service()->planRun($country, TownSuggestionService::BATCH_SIZE);
        $batchId = (string) $plan['batches'][0]->getId();

        $this->suggester->queueNames(['Accra', 'Kumasi']);
        $this->handle($batchId);

        $batch = $this->freshBatch($batchId);
        self::assertSame(
            TownSuggestionBatchStatus::COMPLETED,
            $batch->getStatus(),
            (string) $batch->getErrorMessage(),
        );
        self::assertSame(2, $batch->getImported());

        $this->purgeCountry($country->value);
    }

    private function purgeCountry(string $code): void
    {
        $this->connection->executeStatement(
            "DELETE FROM town_suggestion_batch WHERE country_code = :c",
            ['c' => $code],
        );
        $this->connection->executeStatement(
            "DELETE FROM location_enrichment_run WHERE country_code = :c",
            ['c' => $code],
        );
        $this->connection->executeStatement('DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)', ['c' => $code]);
        $this->connection->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => $code]);
        $this->connection->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => $code]);
        $this->em->clear();
    }

    public function testAFirstFailureRetriesItselfAndIsNotYetCountedAgainstTheRun(): void
    {
        // Counting it now would close a run that is still going to do the work, and the screen
        // would say "done" while a batch was still pending.
        $plan    = $this->service()->planRun(self::COUNTRY, TownSuggestionService::BATCH_SIZE);
        $batchId = (string) $plan['batches'][0]->getId();
        $runId   = (string) $plan['run']->getId();

        $this->suggester->queue(new TownSuggestionUnavailableException('provider timed out'));
        $this->handle($batchId);

        $batch = $this->freshBatch($batchId);
        self::assertSame(
            TownSuggestionBatchStatus::PENDING,
            $batch->getStatus(),
            'back to pending, not left looking stuck mid-call for the whole retry delay',
        );
        self::assertStringContainsString('provider timed out', (string) $batch->getErrorMessage());
        self::assertNull($batch->getFinishedAt(), 'not finished — it is going to run again');

        $run = $this->runRepository->find($runId);
        self::assertSame(EnrichmentRunStatus::RUNNING, $run->getStatus());
        self::assertSame(0, $run->getSucceeded());
        self::assertSame(0, $run->getFailed());

        self::assertCount(1, $this->bus->dispatched);
        self::assertInstanceOf(SuggestTownsBatchMessage::class, $this->bus->dispatched[0]);
        self::assertSame($batchId, $this->bus->dispatched[0]->batchId);

        $stamps = $this->bus->stamps[0];
        self::assertInstanceOf(DelayStamp::class, $stamps[0]);
        self::assertGreaterThan(0, $stamps[0]->getDelay(), 'a briefly unwell provider must not be hammered');
    }

    public function testTheLastAllowedAttemptIsTerminalAndClosesTheRun(): void
    {
        $plan    = $this->service()->planRun(self::COUNTRY, TownSuggestionService::BATCH_SIZE);
        $batchId = (string) $plan['batches'][0]->getId();
        $runId   = (string) $plan['run']->getId();

        // Three attempts: two retries, then terminal.
        $this->suggester->always(new \App\Service\Location\Suggestion\TownSuggestionResult([]));
        $this->suggester->reset();
        for ($i = 0; $i < 3; $i++) {
            $this->suggester->queue(new TownSuggestionUnavailableException('no API key'));
        }

        $this->handle($batchId);
        $this->handle($batchId);
        $this->handle($batchId);

        $batch = $this->freshBatch($batchId);
        self::assertSame(TownSuggestionBatchStatus::FAILED, $batch->getStatus());
        self::assertSame(3, $batch->getAttempts());
        self::assertNotNull($batch->getFinishedAt());

        // Counted, so a run whose every batch dies still closes instead of blocking every
        // future pull behind the stacking guard forever.
        $run = $this->runRepository->find($runId);
        self::assertSame(EnrichmentRunStatus::COMPLETE, $run->getStatus());
        self::assertSame(1, $run->getFailed());

        self::assertCount(2, $this->bus->dispatched, 'two retries, then it gives up');
    }

    public function testATruncatedReplyFailsImmediatelyWithoutBurningRetries(): void
    {
        // Truncation is deterministic: the same ask against the same ceiling truncates in the
        // same place. Retrying it twice more is three billed calls for one answer, which on a
        // feature whose whole design is cost-controlled is the wrong default.
        $plan    = $this->service()->planRun(self::COUNTRY, TownSuggestionService::BATCH_SIZE);
        $batchId = (string) $plan['batches'][0]->getId();
        $runId   = (string) $plan['run']->getId();

        $this->suggester->queue(new \App\Service\Location\Suggestion\TownSuggestionTruncatedException(
            'Claude hit the 6144-token ceiling before finishing town list for Tunisia.',
        ));

        $this->handle($batchId);

        $batch = $this->freshBatch($batchId);
        self::assertSame(TownSuggestionBatchStatus::FAILED, $batch->getStatus());
        self::assertSame(1, $batch->getAttempts(), 'one attempt, not three');
        self::assertStringContainsString('ceiling', (string) $batch->getErrorMessage());
        self::assertNotNull($batch->getFinishedAt());

        self::assertSame([], $this->bus->dispatched, 'nothing re-queued');

        // Still counted, so the run closes rather than waiting for a batch that will not come.
        $run = $this->runRepository->find($runId);
        self::assertSame(EnrichmentRunStatus::COMPLETE, $run->getStatus());
        self::assertSame(1, $run->getFailed());
    }

    public function testAMessageForADeletedBatchIsANoOpRatherThanAnError(): void
    {
        $this->expectNotToPerformAssertions();

        // ids-only messages are expected to outlive their rows occasionally — a run deleted
        // while its batches were in flight must not raise.
        $this->handle('01931f3a-0000-7000-8000-000000000000');
    }

    public function testAnAlreadyCompletedBatchIsNotRunAgain(): void
    {
        // A duplicate delivery must not pay for a second call, nor double-count the run.
        $plan    = $this->service()->planRun(self::COUNTRY, TownSuggestionService::BATCH_SIZE);
        $batchId = (string) $plan['batches'][0]->getId();
        $runId   = (string) $plan['run']->getId();

        $this->suggester->queueNames(['Onceville']);
        $this->handle($batchId);
        $this->handle($batchId);

        self::assertCount(1, $this->suggester->calls(), 'the provider must be asked exactly once');

        $run = $this->runRepository->find($runId);
        self::assertSame(1, $run->getSucceeded(), 'and the run counted exactly once');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function handle(string $batchId): void
    {
        $this->em->clear();

        $handler = new SuggestTownsBatchMessageHandler(
            $this->batchRepository,
            $this->runRepository,
            $this->service(),
            $this->seedService,
            $this->bus,
            new NullLogger(),
        );

        $handler(new SuggestTownsBatchMessage($batchId));
    }

    private function service(): TownSuggestionService
    {
        return new TownSuggestionService(
            $this->suggester,
            new TownSuggestionValidator(),
            $this->seedService,
            self::getContainer()->get(CountryProfileRepository::class),
            $this->batchRepository,
            $this->em,
            $this->connection,
            new NullLogger(),
        );
    }

    private function freshBatch(string $batchId): \App\Entity\Location\TownSuggestionBatch
    {
        $this->em->clear();

        return $this->batchRepository->find($batchId);
    }

    /** @return object{dispatched: list<object>, stamps: list<array>} */
    private function spyBus(): object
    {
        return new class implements MessageBusInterface {
            /** @var list<object> */
            public array $dispatched = [];

            /** @var list<array> */
            public array $stamps = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->dispatched[] = $message;
                $this->stamps[]     = $stamps;

                return new Envelope($message);
            }
        };
    }

    private function purge(): void
    {
        $code = self::COUNTRY->value;

        $this->connection->executeStatement(
            'DELETE FROM town_suggestion_batch WHERE run_id IN (SELECT id FROM location_enrichment_run WHERE country_code = :c)',
            ['c' => $code],
        );
        $this->connection->executeStatement('DELETE FROM location_enrichment_run WHERE country_code = :c', ['c' => $code]);
        $this->connection->executeStatement(
            'DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            ['c' => $code],
        );
        $this->connection->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => $code]);
        $this->connection->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => $code]);
        $this->em->clear();
    }
}
