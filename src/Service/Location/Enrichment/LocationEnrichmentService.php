<?php

declare(strict_types=1);

namespace App\Service\Location\Enrichment;

use App\Entity\Location\Location;
use App\Entity\Location\LocationEnrichmentAttempt;
use App\Entity\Location\LocationEnrichmentRun;
use App\Enum\Location\EnrichmentOutcome;
use App\Enum\Location\EnrichmentStatus;
use App\Message\Location\EnrichLocationMessage;
use App\Repository\Location\LocationEnrichmentAttemptRepository;
use App\Repository\Location\LocationEnrichmentRunRepository;
use App\Repository\Location\LocationRepository;
use App\Service\Location\LandPlotProvisioner;
use App\Service\Location\LocationClassificationService;
use App\Service\Location\NpcClubLocationLinker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The lease, the dispatch, and the write-back. One place, so the lazy read path and the admin
 * batch path cannot drift.
 *
 * TWO GUARANTEES THIS CLASS EXISTS TO PROVIDE.
 *
 * 1. ONE API CALL PER LOCATION, however many people ask for it. queueOnRequest() is a
 *    conditional UPDATE, and only the caller whose UPDATE affected a row dispatches a message.
 *    Fifty simultaneous requests for an un-enriched town therefore produce one paid call and
 *    forty-nine no-ops — which is also what makes a double-tap and a retry-after-timeout free.
 *    This is LeaderboardPeriodCloseService::claim()'s idiom, and it is raw DBAL for the same
 *    reason that is: a failed flush() closes the shared EntityManager, and this runs on a
 *    request path where that would turn a cosmetic problem into a 500 for an unrelated request.
 *
 * 2. AN ADMIN'S CORRECTION IS NEVER UNDONE. Locked fields are skipped by EnrichmentValidator
 *    before they are examined, and applyResult() writes only what the validator accepted.
 *
 * ON FAILURE RECORDING: every attempt — success, partial, rejection, or transport error —
 * writes a LocationEnrichmentAttempt row. That is deliberate, and it is the lesson of the
 * incident that produced NotificationLog: a missing FIREBASE_SERVICE_ACCOUNT_JSON silently
 * discarded every push with no trace at all. An unconfigured or failing enricher must leave
 * evidence, visible on the admin screen, before anyone starts a 865-row sweep.
 */
final class LocationEnrichmentService
{
    /**
     * Radius within which enriching one town invalidates its neighbours' rival sets.
     *
     * A newly placed town changes which towns are its NEIGHBOURS' nearest four, not just its
     * own, so the invalidation has to fan out — otherwise an early-enriched town keeps a stale
     * rival list for ever, because nothing would ever touch its row again.
     */
    public const RIVAL_INVALIDATION_RADIUS_KM = 80.0;

    public function __construct(
        private readonly LocationRepository                  $locationRepository,
        private readonly LocationEnrichmentAttemptRepository $attemptRepository,
        private readonly LocationEnrichmentRunRepository     $runRepository,
        private readonly LocationEnricherInterface           $enricher,
        private readonly EnrichmentValidator                 $validator,
        private readonly MessageBusInterface                 $messageBus,
        private readonly EntityManagerInterface              $em,
        private readonly LoggerInterface                     $logger,
        private readonly LocationClassificationService       $classificationService,
        private readonly NpcClubLocationLinker               $npcLinker,
        private readonly LandPlotProvisioner                 $plotProvisioner,
    ) {}

    /**
     * On-demand path: claim this location and queue it, if it is claimable.
     *
     * Returns true only for the caller that won the claim and dispatched. Everyone else gets
     * false and should simply report the location's current status to the client — false is not
     * a failure, it means somebody else is already paying for this town.
     *
     * NAMED FOR A REQUEST, NOT A READ. It was `queueForRead()` when GET /api/locations/{id}
     * called it as a side effect of being read. That is gone: the only production caller is now
     * POST /api/locations/{id}/enrich, where a person has explicitly asked for a valuation.
     */
    public function queueOnRequest(Location $location): bool
    {
        if (!$this->isLazyCandidate($location)) {
            return false;
        }

        if (!$this->locationRepository->claimForEnrichment($location, new \DateTimeImmutable())) {
            return false;
        }

        $this->messageBus->dispatch(new EnrichLocationMessage((string) $location->getId()));

        return true;
    }

    /**
     * Whether the read path should even attempt a claim.
     *
     * Checked before the UPDATE purely to avoid a pointless statement on the common case of an
     * already-enriched location; the UPDATE's own WHERE clause is the real guard, since it is
     * the only one that is atomic.
     */
    public function isLazyCandidate(Location $location): bool
    {
        if (!$location->isActive()) {
            return false;
        }

        // NEEDS_REVIEW and FAILED are deliberately NOT re-queued by a reader. A town that
        // cannot be resolved would otherwise burn an API call on every single page view; an
        // admin retries those explicitly.
        return $location->getEnrichmentStatus() === EnrichmentStatus::PENDING
            && $location->getEnrichmentAttempts() < Location::MAX_ENRICHMENT_ATTEMPTS;
    }

    /**
     * Handler side: refresh the lease and hand back the row, or null if it is not ours to do.
     *
     * The refresh is the fix for the hazard DrawCompetitionRoundMessage's docblock describes —
     * a lease taken at dispatch counting down while the message waits in a backlog. Here the
     * dispatch-time stamp exists to dedupe, and this re-stamp makes the lease measure time
     * since work actually began.
     */
    public function beginWork(string $locationId): ?Location
    {
        /** @var Location|null $location */
        $location = $this->locationRepository->find($locationId);

        if ($location === null) {
            // Locations go away — an admin can delete one while messages are still queued.
            // Not an error, just a consequence of decoupling.
            return null;
        }

        if ($location->getEnrichmentStatus() !== EnrichmentStatus::QUEUED) {
            // Already settled by another worker, or reset by an admin.
            return null;
        }

        $this->em->getConnection()->executeStatement(
            'UPDATE location SET enrichment_claimed_at = :now WHERE id = :id',
            ['now' => new \DateTimeImmutable(), 'id' => $locationId],
            ['now' => 'datetime_immutable'],
        );

        return $location;
    }

    /**
     * Ask the provider, validate, write what survived, and record the attempt.
     *
     * Exceptions from the provider are caught HERE rather than left to Messenger, because the
     * attempt row is the only trace an unconfigured provider leaves and it must be written
     * before the exception propagates. The exception is then rethrown so Messenger's retry and
     * `failed` transport still apply — the same split NotificationLoggingSubscriber achieves
     * for push sends.
     *
     * @throws EnrichmentUnavailableException rethrown after the attempt row is written
     */
    public function enrich(Location $location, ?LocationEnrichmentRun $run = null): LocationEnrichmentAttempt
    {
        $startedAt = microtime(true);

        try {
            $result = $this->enricher->enrich($location);
        } catch (\Throwable $e) {
            $attempt = $this->recordAttempt(
                $location,
                $run,
                EnrichmentOutcome::ERROR,
                raw: null,
                accepted: null,
                rejected: null,
                error: $e->getMessage(),
                startedAt: $startedAt,
            );

            $this->settle($location, EnrichmentStatus::FAILED, $e->getMessage());

            $this->logger->error('Location enrichment failed', [
                'location' => (string) $location->getId(),
                'town'     => $location->getTown(),
                'provider' => $this->enricher->provider(),
                'error'    => $e->getMessage(),
            ]);

            if ($run !== null) {
                $this->runRepository->recordOutcome((string) $run->getId(), EnrichmentOutcome::ERROR);
            }

            throw $e;
        }

        $verdict = $this->validator->validate($location, $result);
        $outcome = $verdict->outcome();

        $attempt = $this->recordAttempt(
            $location,
            $run,
            $outcome,
            raw: $result->raw === [] ? null : $result->raw,
            accepted: $verdict->accepted === [] ? null : $verdict->accepted,
            rejected: $verdict->rejected === [] ? null : $verdict->rejected,
            error: null,
            startedAt: $startedAt,
        );
        $attempt->setTokenUsage($result->inputTokens, $result->outputTokens);

        $this->applyResult($location, $verdict);

        $status = match ($outcome) {
            EnrichmentOutcome::ACCEPTED => EnrichmentStatus::ENRICHED,
            EnrichmentOutcome::PARTIAL  => EnrichmentStatus::NEEDS_REVIEW,
            default                     => EnrichmentStatus::FAILED,
        };

        $this->settle($location, $status, null);

        if ($run !== null) {
            $this->runRepository->recordOutcome((string) $run->getId(), $outcome);
        }

        return $attempt;
    }

    /** Write the accepted fields, and fan out the rival invalidation if a position changed. */
    private function applyResult(Location $location, ValidatedEnrichment $verdict): void
    {
        $accepted = $verdict->accepted;
        $stamp    = new \DateTimeImmutable();
        $source   = sprintf(
            '%s:%s:%s',
            $this->enricher->provider(),
            $this->enricher->model(),
            $stamp->format('Y-m-d'),
        );

        if (isset($accepted['lat'], $accepted['lng'])) {
            // setCoordinates() nulls this location's own rivalsComputedAt; the neighbour
            // fan-out below is the other half and cannot be done from inside an entity.
            $location->setCoordinates((float) $accepted['lat'], (float) $accepted['lng']);
        }

        $populationMoved = isset($accepted['population'])
            && (int) $accepted['population'] !== $location->getPopulation();

        if (isset($accepted['population'])) {
            $location->setPopulation((int) $accepted['population']);
            $location->setPopulationSource($source);
            $location->setPopulationAsOf($stamp);
        }

        if (isset($accepted['landPence'])) {
            $location->setLandPence((int) $accepted['landPence']);
            $location->setLandValuedAt($stamp);
            $location->setLandSource($source);
        }

        if (isset($accepted['region'])) {
            $location->setRegion((string) $accepted['region']);
        }

        $location->setEnrichmentProvider($this->enricher->provider());
        $location->setEnrichmentModel($this->enricher->model());
        $location->setEnrichedAt($stamp);

        $this->em->flush();

        if ($populationMoved) {
            $this->cascadePopulationChange($location);
        }

        $this->provisionPlots($location);

        if ($verdict->hasCoordinates()) {
            $invalidated = $this->locationRepository->invalidateRivalsNear(
                $location,
                self::RIVAL_INVALIDATION_RADIUS_KM,
            );

            if ($invalidated > 0) {
                $this->logger->info('Rival sets invalidated by a newly placed location', [
                    'location'    => (string) $location->getId(),
                    'invalidated' => $invalidated,
                ]);
            }
        }
    }

    /**
     * Final status, attempt counter and error text, in one raw statement.
     *
     * Raw rather than through the ORM because this runs in a worker draining many messages: a
     * unique or constraint failure out of flush() would close the shared EntityManager and
     * strand every remaining message in the batch.
     */
    private function settle(Location $location, EnrichmentStatus $status, ?string $error): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE location
                SET enrichment_status = :status,
                    enrichment_attempts = enrichment_attempts + 1,
                    enrichment_error = :error,
                    enrichment_claimed_at = NULL
              WHERE id = :id',
            [
                'status' => $status->value,
                'error'  => $error === null ? null : mb_substr($error, 0, 255),
                'id'     => (string) $location->getId(),
            ],
        );
    }

    /**
     * @param array<string, mixed>|null  $raw
     * @param array<string, mixed>|null  $accepted
     * @param array<string, string>|null $rejected
     */
    private function recordAttempt(
        Location $location,
        ?LocationEnrichmentRun $run,
        EnrichmentOutcome $outcome,
        ?array $raw,
        ?array $accepted,
        ?array $rejected,
        ?string $error,
        float $startedAt,
    ): LocationEnrichmentAttempt {
        $attempt = new LocationEnrichmentAttempt(
            $location,
            $this->enricher->provider(),
            $this->enricher->model(),
            $this->enricher->promptVersion(),
            $outcome,
            $run,
        );

        $attempt->setRawResponseJson($raw);
        $attempt->setAcceptedFields($accepted);
        $attempt->setRejectedFields($rejected);
        $attempt->setErrorMessage($error);
        $attempt->setDurationMs((int) round((microtime(true) - $startedAt) * 1000));

        $this->em->persist($attempt);
        $this->em->flush();

        return $attempt;
    }

    /**
     * A population landed, so the country's size bands and its NPC clubs have to follow.
     *
     * CITY SIZE IS A PERCENTILE SPLIT across the whole country's set, not a threshold on one
     * row — so one town's new population can push OTHER towns across a band boundary. That is
     * why this reclassifies the country rather than the location, and re-syncs every linked club
     * in it rather than just this town's.
     *
     * This is the path that makes enrichment actually reach NPC clubs. Without it, an AI
     * population would update `location` and stop there, leaving the clubs standing in that town
     * on the figures they were generated with.
     *
     * NEVER THROWS INTO THE CALLER. An enrichment attempt that produced good data must be
     * recorded as a success even if the downstream cascade fails; the attempt row and the
     * location write have already been committed by this point, and `app:npc-clubs:link-locations
     * --relink` repairs the cascade at any time. Failing here would roll back nothing and lose
     * the attempt's outcome.
     *
     * DOES NOT INVALIDATE WORLD-PACK CACHES, deliberately. They are per country and tier with no
     * TTL, and clients keep receiving the old values until that country is rebuilt — see
     * docs/api/npc-club-city-size.md. Rebuilding on every enriched row would make a sweep
     * regenerate a country hundreds of times.
     */
    /**
     * THE TOWN GETS ITS PLOTS. This is what makes "find a plot on demand" work.
     *
     * An imported town arrives as a bare name — no population, no size, and therefore no plots
     * at all, so it cannot be bought and the API reports it as `awaiting_enrichment`. Learning
     * its population tells us how big it is, and how big it is decides how much football it
     * holds, so the plots appear here rather than waiting for somebody to run a command.
     *
     * RUNS ON EVERY ACCEPTED ENRICHMENT, not only when the population MOVED, and that is the
     * whole point of it not living in cascadePopulationChange(). A town whose seeded population
     * the AI simply confirms has `$populationMoved === false` — nothing changed — and under the
     * cascade it would stay plotless for ever, enriched and unbuyable, with nothing in the
     * response explaining why. Provisioning is a top-up keyed on what already exists, so running
     * it on a town that is already provisioned writes nothing.
     *
     * TOPS UP ONLY, NEVER REMOVES. A town that re-bands DOWNWARD keeps the plots it has, because
     * one of them may already carry a claim and somebody's real money.
     *
     * NEVER THROWS INTO THE CALLER, for the same reason as the cascade: an attempt that produced
     * good data is a success, and `app:locations:provision-plots` repairs this at any time.
     */
    private function provisionPlots(Location $location): void
    {
        try {
            $plots = $this->plotProvisioner->provisionLocation($location);

            if ($plots > 0) {
                $this->logger->info('Plots provisioned for a newly priced town', [
                    'location' => (string) $location->getId(),
                    'plots'    => $plots,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Could not provision plots after enrichment', [
                'location'  => (string) $location->getId(),
                'exception' => $e,
            ]);
        }
    }

    private function cascadePopulationChange(Location $location): void
    {
        $countryCode = $location->getCountryCode();

        try {
            $this->classificationService->reclassifyCountry($countryCode);

            $resynced = $this->npcLinker->resyncCountry($countryCode);

            if ($resynced > 0) {
                $this->logger->info('NPC clubs re-synced after an enriched population', [
                    'country'  => $countryCode,
                    'location' => (string) $location->getId(),
                    'resynced' => $resynced,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Could not cascade a population change to city sizes or NPC clubs', [
                'country'   => $countryCode,
                'location'  => (string) $location->getId(),
                'exception' => $e,
            ]);
        }
    }
}
