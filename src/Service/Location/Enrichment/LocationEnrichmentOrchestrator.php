<?php

declare(strict_types=1);

namespace App\Service\Location\Enrichment;

use App\Entity\Admin;
use App\Entity\Location\LocationEnrichmentRun;
use App\Enum\Location\EnrichmentStatus;
use App\Enum\Location\EnrichmentTrigger;
use App\Message\Location\EnrichLocationMessage;
use App\Repository\Location\LocationEnrichmentRunRepository;
use App\Repository\Location\LocationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The three admin triggers — one location, a selection, everything outstanding — and the CLI
 * behind them.
 *
 * Modelled on WorldPackGenerationOrchestrator: a run header, then one queued message per item.
 * The admin screen and app:locations:enrich both come through here so their behaviour cannot
 * diverge, which matters because one of them is the thing an operator reaches for at 2am.
 *
 * TWO GUARDS, BOTH LEARNED FROM OTHER FEATURES IN THIS CODEBASE.
 *
 * 1. A SWEEP CANNOT BE STACKED. Every message on this queue costs real money, and the obvious
 *    operator mistake is pressing "enrich all remaining" twice because the first press showed
 *    no immediate change. countUndeliveredOnQueue() refuses while a sweep is still draining —
 *    the technique StarterPoolAuditService uses for exactly the same reason, there to stop a
 *    nightly top-up stacking on itself.
 *
 * 2. CLAIMING IS SET-BASED AND RETURNS WHAT IT WON. claimBatchForEnrichment() marks rows
 *    QUEUED in one statement with FOR UPDATE SKIP LOCKED and RETURNS the ids, so two admins
 *    pressing the button simultaneously split the work rather than both queueing all of it.
 *    Messages are dispatched only for rows this call actually claimed.
 *
 * ENRICHED ROWS ARE NEVER RE-ENRICHED by any of these paths. Re-enrichment on a schedule is a
 * separate product decision (land values do drift, and `land_valued_at` exists in anticipation)
 * and it interacts with sell-back pricing, so it is deliberately not wired here.
 */
final class LocationEnrichmentOrchestrator
{
    /** Must match the `enrichment` transport's queue_name in config/packages/messenger.yaml. */
    public const TRANSPORT_QUEUE = 'enrichment';

    /** Hard ceiling on one sweep, so a global run is a known quantity rather than a surprise. */
    public const MAX_SWEEP = 2000;

    public function __construct(
        private readonly LocationRepository              $locationRepository,
        private readonly LocationEnrichmentRunRepository $runRepository,
        private readonly LocationEnricherInterface       $enricher,
        private readonly MessageBusInterface             $messageBus,
        private readonly EntityManagerInterface          $em,
    ) {}

    /** Undrained messages still sitting on the enrichment queue. */
    public function pendingOnQueue(): int
    {
        return $this->runRepository->countUndeliveredOnQueue(self::TRANSPORT_QUEUE);
    }

    public function isSweepInProgress(): bool
    {
        return $this->pendingOnQueue() > 0;
    }

    /**
     * Enrich one location, from its admin detail page.
     *
     * Not subject to the sweep guard: a single deliberate retry of one town is exactly what an
     * operator does while working the needs-review queue, and blocking it behind a draining
     * sweep would make that impossible.
     */
    public function queueSingle(string $locationId, ?Admin $admin = null): ?LocationEnrichmentRun
    {
        return $this->queue(
            [$locationId],
            EnrichmentTrigger::ADMIN_SINGLE,
            null,
            $admin,
        );
    }

    /**
     * Enrich a selection from the CRUD index.
     *
     * @param list<string> $locationIds
     */
    public function queueSubset(array $locationIds, ?Admin $admin = null): ?LocationEnrichmentRun
    {
        return $this->queue($locationIds, EnrichmentTrigger::ADMIN_SUBSET, null, $admin);
    }

    /**
     * Enrich everything still outstanding, for one country or globally.
     *
     * @param list<EnrichmentStatus>|null $statuses defaults to PENDING and FAILED; pass
     *                                              NEEDS_REVIEW explicitly to re-ask about rows
     *                                              a human has not yet looked at
     *
     * @throws EnrichmentSweepInProgressException when a previous sweep has not drained
     */
    public function queueAllOutstanding(
        ?string $countryCode = null,
        ?Admin $admin = null,
        ?array $statuses = null,
        int $limit = self::MAX_SWEEP,
    ): ?LocationEnrichmentRun {
        $pending = $this->pendingOnQueue();

        if ($pending > 0) {
            throw new EnrichmentSweepInProgressException(sprintf(
                '%d enrichment message(s) are still queued. Wait for the current sweep to drain '
                . 'before starting another — every message is a paid API call.',
                $pending,
            ));
        }

        $statuses ??= [EnrichmentStatus::PENDING, EnrichmentStatus::FAILED];

        $claimed = $this->locationRepository->claimBatchForEnrichment(
            $statuses,
            new \DateTimeImmutable(),
            min($limit, self::MAX_SWEEP),
            $countryCode,
        );

        if ($claimed === []) {
            return null;
        }

        return $this->dispatchRun($claimed, EnrichmentTrigger::ADMIN_ALL, $countryCode, $admin);
    }

    /**
     * How many rows a sweep would claim, without claiming any.
     *
     * Backs both --dry-run and the admin confirmation, so the number an operator is shown is
     * the number the real call would act on.
     *
     * @param list<EnrichmentStatus>|null $statuses
     */
    public function countOutstanding(?string $countryCode = null, ?array $statuses = null): int
    {
        $statuses ??= [EnrichmentStatus::PENDING, EnrichmentStatus::FAILED];
        $counts     = $this->locationRepository->countByEnrichmentStatus($countryCode);

        $total = 0;
        foreach ($statuses as $status) {
            $total += $counts[$status->value] ?? 0;
        }

        return $total;
    }

    /**
     * @param list<string>                $locationIds
     * @param list<EnrichmentStatus>|null $statuses
     */
    private function queue(
        array $locationIds,
        EnrichmentTrigger $trigger,
        ?string $countryCode,
        ?Admin $admin,
    ): ?LocationEnrichmentRun {
        $locationIds = array_values(array_unique(array_filter($locationIds)));

        if ($locationIds === []) {
            return null;
        }

        // Even a hand-picked selection goes through the claim, so a row already queued by the
        // lazy path or another admin is not enriched twice.
        $claimed = $this->locationRepository->claimSpecificForEnrichment(
            $locationIds,
            new \DateTimeImmutable(),
        );

        if ($claimed === []) {
            return null;
        }

        return $this->dispatchRun($claimed, $trigger, $countryCode, $admin);
    }

    /** @param list<string> $locationIds */
    private function dispatchRun(
        array $locationIds,
        EnrichmentTrigger $trigger,
        ?string $countryCode,
        ?Admin $admin,
    ): LocationEnrichmentRun {
        $run = new LocationEnrichmentRun(
            $trigger,
            $this->enricher->provider(),
            $this->enricher->model(),
            $countryCode,
            $admin,
        );
        $run->setQueued(count($locationIds));

        $this->em->persist($run);
        // Flushed before dispatch so a handler picking the message up immediately can find its
        // run row. The in-memory transport in tests makes that ordering observable.
        $this->em->flush();

        foreach ($locationIds as $locationId) {
            $this->messageBus->dispatch(new EnrichLocationMessage($locationId, (string) $run->getId()));
        }

        return $run;
    }
}
