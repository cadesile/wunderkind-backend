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
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The lease, the dispatch, and the write-back. One place, so the lazy read path and the admin
 * batch path cannot drift.
 *
 * TWO GUARANTEES THIS CLASS EXISTS TO PROVIDE.
 *
 * 1. ONE API CALL PER LOCATION, however many people look at it. queueForRead() is a
 *    conditional UPDATE, and only the caller whose UPDATE affected a row dispatches a message.
 *    Fifty simultaneous viewers of an un-enriched town therefore produce one paid call and
 *    forty-nine no-ops. This is LeaderboardPeriodCloseService::claim()'s idiom applied to a
 *    GET, and it is raw DBAL for the same reason that is: a failed flush() closes the shared
 *    EntityManager, and this runs on a public read path where that would turn a cosmetic
 *    problem into a 500 for an unrelated request.
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
    ) {}

    /**
     * Lazy path: claim this location and queue it, if it is claimable.
     *
     * Returns true only for the caller that won the claim and dispatched. Everyone else gets
     * false and should simply report the location's current status to the client.
     */
    public function queueForRead(Location $location): bool
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
}
