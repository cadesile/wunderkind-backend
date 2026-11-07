<?php

declare(strict_types=1);

namespace App\Tests\Service\Location\Suggestion;

use App\Entity\Location\TownSuggestionBatch;
use App\Enum\Country;
use App\Enum\Location\EnrichmentRunStatus;
use App\Enum\Location\EnrichmentTrigger;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\Location\LocationEnrichmentRunRepository;
use App\Repository\Location\TownSuggestionBatchRepository;
use App\Service\Location\LocationSeedService;
use App\Service\Location\Suggestion\TownSuggestionService;
use App\Service\Location\Suggestion\TownSuggestionValidator;
use App\Tests\Support\FakeTownSuggester;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The queued, batched town pull.
 *
 * The synchronous version asked for 300 towns in one call — a ~12,000 output-token generation
 * that nginx cut off at 60s while PHP kept waiting, so the operator saw a failure for work that
 * was often still running. This splits it, and the properties worth pinning are the ones that
 * make splitting *pay*: the division itself, the run closing itself, and above all that a later
 * batch is told what the earlier ones already offered.
 *
 * Uses TN (Tunisia, 30 registry towns) and a fake suggester, like its sibling test — the suite
 * must never make a real, billed call.
 */
class TownSuggestionBatchingTest extends KernelTestCase
{
    private const COUNTRY = Country::TN;

    private EntityManagerInterface $em;
    private Connection $connection;
    private LocationSeedService $seedService;
    private FakeTownSuggester $suggester;
    private TownSuggestionBatchRepository $batchRepository;
    private LocationEnrichmentRunRepository $runRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em              = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection      = self::getContainer()->get(Connection::class);
        $this->seedService     = self::getContainer()->get(LocationSeedService::class);
        $this->batchRepository = self::getContainer()->get(TownSuggestionBatchRepository::class);
        $this->runRepository   = self::getContainer()->get(LocationEnrichmentRunRepository::class);
        $this->suggester       = new FakeTownSuggester();

        $this->purge();
        $this->seedService->seedCountry(self::COUNTRY);
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    // ── The division ────────────────────────────────────────────────────────

    public function testADefaultRunDividesIntoWholeBatches(): void
    {
        $sizes = TownSuggestionService::planBatchSizes(TownSuggestionService::DEFAULT_MAX);

        self::assertCount(6, $sizes, '300 towns at 50 a batch');
        self::assertSame([50, 50, 50, 50, 50, 50], $sizes);
        self::assertSame(TownSuggestionService::DEFAULT_MAX, array_sum($sizes));
    }

    public function testARemainderBecomesItsOwnSmallerBatch(): void
    {
        // The last batch must ask for the remainder, not a full 50 — otherwise a run for 120
        // quietly pays for 150 towns' worth of generation.
        self::assertSame([50, 50, 20], TownSuggestionService::planBatchSizes(120));
        self::assertSame(120, array_sum(TownSuggestionService::planBatchSizes(120)));
    }

    public function testASmallRunIsASingleBatch(): void
    {
        self::assertSame([1], TownSuggestionService::planBatchSizes(1));
        self::assertSame([7], TownSuggestionService::planBatchSizes(7));
    }

    public function testAnOutOfRangeRequestIsClampedRatherThanTrusted(): void
    {
        $capped = TownSuggestionService::planBatchSizes(99_999);

        self::assertSame(TownSuggestionService::HARD_MAX, array_sum($capped));
        self::assertSame([1], TownSuggestionService::planBatchSizes(0));
        self::assertSame([1], TownSuggestionService::planBatchSizes(-5));
    }

    // ── Planning writes rows ────────────────────────────────────────────────

    public function testPlanningWritesARunAndABatchRowEach(): void
    {
        $plan = $this->service()->planRun(self::COUNTRY, 120);

        self::assertCount(3, $plan['batches']);

        $run = $plan['run'];
        self::assertSame(EnrichmentTrigger::ADMIN_TOWN_BATCH, $run->getTrigger());
        self::assertSame(EnrichmentRunStatus::RUNNING, $run->getStatus());

        // `queued` is the BATCH count, not a town count. It has to be — it is written before
        // anything has been asked, and it is what lets the last batch close the run.
        self::assertSame(3, $run->getQueued(), 'queued counts batches for this trigger');

        $indexes = array_map(static fn (TownSuggestionBatch $b): int => $b->getBatchIndex(), $plan['batches']);
        self::assertSame([1, 2, 3], $indexes, '1-based, so it reads as "batch 3 of 3"');

        foreach ($plan['batches'] as $batch) {
            self::assertSame(3, $batch->getTotalBatches(), 'denormalised so "of 3" needs no join');
            self::assertSame(self::COUNTRY->value, $batch->getCountryCode());
        }

        self::assertSame([50, 50, 20], array_map(
            static fn (TownSuggestionBatch $b): int => $b->getRequested(),
            $plan['batches'],
        ));
    }

    public function testPlanningRefusesACountryWithNoProfile(): void
    {
        // location rows carry an FK to country_profile, so the profile cannot go first.
        $this->connection->executeStatement(
            'DELETE FROM land_plot WHERE location_id IN (SELECT id FROM location WHERE country_code = :c)',
            ['c' => self::COUNTRY->value],
        );
        $this->connection->executeStatement('DELETE FROM location WHERE country_code = :c', ['c' => self::COUNTRY->value]);
        $this->connection->executeStatement('DELETE FROM country_profile WHERE code = :c', ['c' => self::COUNTRY->value]);
        $this->em->clear();

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->planRun(self::COUNTRY, 50);
    }

    // ── Running a batch ─────────────────────────────────────────────────────

    public function testABatchImportsItsTownsAndReportsWhatItOffered(): void
    {
        $plan  = $this->service()->planRun(self::COUNTRY, 50);
        $batch = $plan['batches'][0];

        $this->suggester->queueNames(['Batchville', 'Queuetown']);

        $summary = $this->service()->runBatch(
            (string) $plan['run']->getId(),
            (string) $batch->getId(),
            self::COUNTRY,
            $batch->getRequested(),
        );

        self::assertSame(2, $summary['offered']);
        self::assertSame(2, $summary['imported']);
        self::assertSame(['Batchville', 'Queuetown'], $summary['offeredNames']);
        self::assertSame(1, $this->townCount('Batchville'));
    }

    public function testABatchAsksForItsOwnSizeNotTheWholeRun(): void
    {
        $plan  = $this->service()->planRun(self::COUNTRY, 120);
        $third = $plan['batches'][2];

        $this->suggester->queueNames([]);
        $this->service()->runBatch(
            (string) $plan['run']->getId(),
            (string) $third->getId(),
            self::COUNTRY,
            $third->getRequested(),
        );

        $calls = $this->suggester->calls();
        self::assertSame(20, $calls[0]['max'], 'the remainder batch asks for 20, not 120');
        self::assertSame(
            TownSuggestionService::BATCH_TIMEOUT_SECONDS,
            $calls[0]['timeout'],
            'a batch passes the short timeout, not the 120s one sized for a big single call',
        );
    }

    /**
     * The whole reason TownSuggestionBatch stores names.
     *
     * Without this, every batch asks the identical question — "the biggest towns you do not
     * hold" — and the only thing that would change the answer is the exclusion list. Freshly
     * imported towns have no population, so they sort into the tail of the held-towns list that
     * the prompt truncates away; batch four would then pay for exactly what batch one took.
     */
    public function testALaterBatchIsToldWhatEarlierBatchesAlreadyOffered(): void
    {
        $plan   = $this->service()->planRun(self::COUNTRY, 100);
        $first  = $plan['batches'][0];
        $second = $plan['batches'][1];

        $this->suggester->queueNames(['Firstville', 'Secondtown']);
        $this->service()->runBatch(
            (string) $plan['run']->getId(),
            (string) $first->getId(),
            self::COUNTRY,
            $first->getRequested(),
        );

        $this->suggester->reset();
        $this->suggester->queueNames([]);
        $this->service()->runBatch(
            (string) $plan['run']->getId(),
            (string) $second->getId(),
            self::COUNTRY,
            $second->getRequested(),
        );

        $exclusions = $this->suggester->calls()[0]['exclude'];

        self::assertContains('Firstville', $exclusions);
        self::assertContains('Secondtown', $exclusions);

        // AND AT THE FRONT. Truncation takes the tail, so position is the guarantee, not mere
        // presence — a run's own names buried behind a few hundred registry towns would be cut.
        self::assertSame(
            ['Firstville', 'Secondtown'],
            \array_slice($exclusions, 0, 2),
            "the run's own names must lead the exclusion list, or truncation will drop them",
        );
    }

    public function testABatchIsNotToldWhatItItselfOffered(): void
    {
        // It is about to be replaced by a retry of the same batch; excluding its own previous
        // answer would make a retry ask a different question than the attempt it replaces.
        $plan  = $this->service()->planRun(self::COUNTRY, 50);
        $batch = $plan['batches'][0];

        $this->suggester->queueNames(['Onlyville']);
        $this->service()->runBatch(
            (string) $plan['run']->getId(),
            (string) $batch->getId(),
            self::COUNTRY,
            $batch->getRequested(),
        );

        $this->suggester->reset();
        $this->suggester->queueNames([]);
        $this->service()->runBatch(
            (string) $plan['run']->getId(),
            (string) $batch->getId(),
            self::COUNTRY,
            $batch->getRequested(),
        );

        $siblingSourced = $this->batchRepository->offeredNamesForOtherBatches(
            (string) $plan['run']->getId(),
            (string) $batch->getId(),
        );

        self::assertNotContains('Onlyville', $siblingSourced);
    }

    // ── The run closing itself ──────────────────────────────────────────────

    public function testTheRunStaysOpenUntilItsLastBatchSettles(): void
    {
        $plan  = $this->service()->planRun(self::COUNTRY, 100); // two batches
        $runId = (string) $plan['run']->getId();

        $this->runRepository->recordBatchOutcome($runId, failed: false);
        $this->em->clear();

        self::assertSame(
            EnrichmentRunStatus::RUNNING,
            $this->runRepository->find($runId)->getStatus(),
            'one of two batches settled is not a finished run',
        );

        $this->runRepository->recordBatchOutcome($runId, failed: false);
        $this->em->clear();

        $run = $this->runRepository->find($runId);
        self::assertSame(EnrichmentRunStatus::COMPLETE, $run->getStatus());
        self::assertNotNull($run->getFinishedAt(), 'closing the run must stamp when');
        self::assertSame(2, $run->getSucceeded());
    }

    public function testARunWhoseBatchesAllFailStillCloses(): void
    {
        // Otherwise a provider outage leaves a run at "running" forever, and the stacking guard
        // on the admin screen would refuse every future pull.
        $plan  = $this->service()->planRun(self::COUNTRY, 50);
        $runId = (string) $plan['run']->getId();

        $this->runRepository->recordBatchOutcome($runId, failed: true);
        $this->em->clear();

        $run = $this->runRepository->find($runId);
        self::assertSame(EnrichmentRunStatus::COMPLETE, $run->getStatus());
        self::assertSame(1, $run->getFailed());
        self::assertSame(0, $run->getSucceeded());
    }

    public function testImportedTownsAreSummedAcrossBatches(): void
    {
        $plan  = $this->service()->planRun(self::COUNTRY, 100);
        $runId = (string) $plan['run']->getId();

        $this->batchRepository->markCompleted((string) $plan['batches'][0]->getId(), 3, 3, 0);
        $this->batchRepository->markCompleted((string) $plan['batches'][1]->getId(), 2, 2, 0);

        self::assertSame(5, $this->batchRepository->importedForRun($runId));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

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

    private function townCount(string $town): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM location WHERE country_code = :c AND town = :t',
            ['c' => self::COUNTRY->value, 't' => $town],
        );
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
