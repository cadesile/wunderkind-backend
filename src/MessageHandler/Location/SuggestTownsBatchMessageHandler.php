<?php

declare(strict_types=1);

namespace App\MessageHandler\Location;

use App\Enum\Location\TownSuggestionBatchStatus;
use App\Message\Location\SuggestTownsBatchMessage;
use App\Repository\Location\LocationEnrichmentRunRepository;
use App\Repository\Location\TownSuggestionBatchRepository;
use App\Service\Location\LocationSeedService;
use App\Service\Location\Suggestion\TownSuggestionService;
use App\Service\Location\Suggestion\TownSuggestionTruncatedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Runs one batch of a queued "Pull towns" run.
 *
 * NEVER LETS AN EXCEPTION ESCAPE, and that is a deliberate departure from
 * EnrichLocationMessageHandler, which rethrows so the `failed` transport can replay it. Retry
 * is self-managed here, on the batch row's own `attempts` column — the same choice
 * WarmWorldPackClubMessageHandler makes and for the same stated reason: one source of truth for
 * what the progress UI shows, not two independently-counting retry mechanisms that can
 * disagree. Letting Messenger's invisible retries run under a live progress bar would make a
 * batch flash "failed" and then un-fail itself.
 *
 * ONE FAILURE IS EXEMPT: a truncated reply is terminal immediately. It is deterministic, so
 * retrying it is purely a way of spending three times as much to learn the same thing.
 *
 * There is a second reason specific to this queue. No retry_strategy is configured anywhere, so
 * Symfony's defaults apply (three retries); three stacked 60s provider timeouts would be 180s
 * inside a consumer whose whole budget is 280s, and the process would exit mid-chain leaving a
 * run that looks stalled with nothing to explain it.
 *
 * HOLDS NO ENTITY ACROSS THE WORK. TownSuggestionService::runBatch() ends up in
 * LocationSeedService::importNames(), which calls em->clear() — but only when it actually
 * imported something, so an entity held across it is detached intermittently, which is the
 * worst kind. Everything after the call is written with raw statements, which are immune.
 */
#[AsMessageHandler]
final class SuggestTownsBatchMessageHandler
{
    /**
     * Counting the first run, so two retries.
     *
     * Low on purpose: every attempt is a billed call, and the common failures here (no API key,
     * a country with no profile) will fail identically however many times they are tried.
     */
    private const MAX_ATTEMPTS = 3;

    /** Multiplied by the attempt number, so 20s then 40s. */
    private const RETRY_DELAY_MS = 20_000;

    public function __construct(
        private readonly TownSuggestionBatchRepository $batchRepository,
        private readonly LocationEnrichmentRunRepository $runRepository,
        private readonly TownSuggestionService $suggestionService,
        private readonly LocationSeedService $seedService,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SuggestTownsBatchMessage $message): void
    {
        $batch = $this->batchRepository->find($message->batchId);

        if ($batch === null) {
            // The run was deleted while its messages were in flight. Nothing to do, and not an
            // error — ids-only messages are expected to outlive their rows occasionally.
            return;
        }

        if ($batch->getStatus() === TownSuggestionBatchStatus::COMPLETED) {
            return; // a prior delivery already did this one
        }

        // Read every scalar we will need NOW, before anything can detach the entity.
        $batchId      = (string) $batch->getId();
        $runId        = (string) $batch->getRun()->getId();
        $countryCode  = $batch->getCountryCode();
        $requested    = $batch->getRequested();
        $batchIndex   = $batch->getBatchIndex();
        $totalBatches = $batch->getTotalBatches();
        $attempt      = $batch->getAttempts() + 1;

        $this->batchRepository->markRunning($batchId, $attempt);

        try {
            // resolveAnyCountry(), NOT resolveCountry(). The latter refuses a country the
            // registry has no curated place list for, which is right for seeding and fatal
            // here: pulling town names is how a country with no list gets one. The planning
            // side was fixed and this was not, so a pull for such a country queued its
            // batches happily and then failed every one of them with "nothing to seed".
            $country = $this->seedService->resolveAnyCountry($countryCode);
            $summary = $this->suggestionService->runBatch($runId, $batchId, $country, $requested);
        } catch (TownSuggestionTruncatedException $e) {
            // TERMINAL ON THE FIRST ATTEMPT, no retry. The reply ran past the token ceiling,
            // and the same ask against the same ceiling truncates in the same place every
            // time — so retrying would be two more billed calls to be told the same thing.
            // Only a smaller batch or a bigger budget fixes it, and neither is this handler's
            // to decide.
            $this->logger->error('Town suggestion batch truncated; not retrying', [
                'runId' => $runId, 'batchId' => $batchId,
                'batch' => \sprintf('%d/%d', $batchIndex, $totalBatches),
                'country' => $countryCode, 'exception' => $e,
            ]);
            $this->batchRepository->markFailed($batchId, $e->getMessage());
            $this->runRepository->recordBatchOutcome($runId, failed: true);

            return;
        } catch (\Throwable $e) {
            $this->handleFailure($batchId, $runId, $attempt, $batchIndex, $totalBatches, $countryCode, $e);

            return;
        }

        // Offered names and count are already recorded by runBatch(), as soon as the model
        // answered — see TownSuggestionBatchRepository::recordOffered().
        $this->batchRepository->markCompleted(
            $batchId,
            $summary['accepted'],
            $summary['imported'],
            $summary['alreadyHeld'],
        );

        $this->runRepository->recordBatchOutcome($runId, failed: false);
    }

    private function handleFailure(
        string $batchId,
        string $runId,
        int $attempt,
        int $batchIndex,
        int $totalBatches,
        string $countryCode,
        \Throwable $e,
    ): void {
        $context = [
            'runId'   => $runId,
            'batchId' => $batchId,
            'batch'   => \sprintf('%d/%d', $batchIndex, $totalBatches),
            'country' => $countryCode,
            'attempt' => $attempt,
        ];

        if ($attempt < self::MAX_ATTEMPTS) {
            $this->logger->warning('Town suggestion batch failed, retrying', $context + ['exception' => $e]);
            $this->batchRepository->markRetrying($batchId, $e->getMessage());

            $this->messageBus->dispatch(
                new SuggestTownsBatchMessage($batchId),
                [new DelayStamp(self::RETRY_DELAY_MS * $attempt)],
            );

            return;
        }

        $this->logger->error('Town suggestion batch failed terminally', $context + ['exception' => $e]);
        $this->batchRepository->markFailed($batchId, $e->getMessage());

        // Counts against the run, so a run whose every batch dies still closes rather than
        // sitting at "running" forever waiting for a tick that will never come.
        $this->runRepository->recordBatchOutcome($runId, failed: true);
    }
}
