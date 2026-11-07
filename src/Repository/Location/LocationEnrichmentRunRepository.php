<?php

declare(strict_types=1);

namespace App\Repository\Location;

use App\Entity\Location\LocationEnrichmentRun;
use App\Enum\Location\EnrichmentOutcome;
use App\Enum\Location\EnrichmentRunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LocationEnrichmentRun>
 */
class LocationEnrichmentRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LocationEnrichmentRun::class);
    }

    /** @return list<LocationEnrichmentRun> newest first */
    public function findRecent(int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<LocationEnrichmentRun> */
    public function findRunning(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :running')
            ->setParameter('running', EnrichmentRunStatus::RUNNING)
            ->orderBy('r.startedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Advance a run's counters by one settled attempt.
     *
     * Raw, atomic, and never through the ORM. Handlers run concurrently on the enrichment
     * queue, so a read-modify-write through hydrated entities would lose counts; and a failed
     * flush() closes the shared EntityManager, which in a worker draining hundreds of messages
     * would strand every remaining one. The LeaderboardPeriodCloseService reasoning, applied to
     * a counter.
     *
     * Also closes the run when the last outstanding attempt settles, so nothing has to poll.
     */
    public function recordOutcome(string $runId, EnrichmentOutcome $outcome): void
    {
        $column = match ($outcome) {
            EnrichmentOutcome::ACCEPTED => 'succeeded',
            EnrichmentOutcome::PARTIAL  => 'needs_review',
            default                     => 'failed',
        };

        $this->getEntityManager()->getConnection()->executeStatement(
            sprintf(
                'UPDATE location_enrichment_run
                    SET %1$s = %1$s + 1,
                        status = CASE
                            WHEN succeeded + needs_review + failed + 1 >= queued THEN :complete
                            ELSE status
                        END,
                        finished_at = CASE
                            WHEN succeeded + needs_review + failed + 1 >= queued THEN :now
                            ELSE finished_at
                        END
                  WHERE id = :id',
                $column,
            ),
            [
                'complete' => EnrichmentRunStatus::COMPLETE->value,
                'now'      => new \DateTimeImmutable(),
                'id'       => $runId,
            ],
            ['now' => 'datetime_immutable'],
        );
    }

    /**
     * Advance a queued town-suggestion run's counters by one settled BATCH.
     *
     * A sibling of recordOutcome() rather than a reuse of it. That method maps a four-valued
     * EnrichmentOutcome onto succeeded/needs_review/failed; a batch is two-valued, so calling
     * it with ACCEPTED to mean "batch done" would be a lie this file then has to keep telling.
     *
     * Raw and atomic for recordOutcome()'s reasons, plus one of its own: the handler calls this
     * AFTER LocationSeedService::importNames(), which ends in em->clear() when it imported
     * anything. A write through a hydrated run entity would be a write to a detached object —
     * silently lost, and only on the batches that actually did something.
     *
     * The completion predicate is recordOutcome()'s, unchanged: `queued` holds the batch count,
     * so the last batch to settle closes the run and nothing has to poll. `needs_review` is not
     * in the sum because a batch has no partial verdict — see TownSuggestionBatchStatus.
     */
    public function recordBatchOutcome(string $runId, bool $failed): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE location_enrichment_run
                SET succeeded = succeeded + :ok,
                    failed    = failed + :bad,
                    status = CASE
                        WHEN succeeded + failed + 1 >= queued THEN :complete
                        ELSE status
                    END,
                    finished_at = CASE
                        WHEN succeeded + failed + 1 >= queued THEN :now
                        ELSE finished_at
                    END
              WHERE id = :id',
            [
                'ok'       => $failed ? 0 : 1,
                'bad'      => $failed ? 1 : 0,
                'complete' => EnrichmentRunStatus::COMPLETE->value,
                'now'      => new \DateTimeImmutable(),
                'id'       => $runId,
            ],
            ['now' => 'datetime_immutable'],
        );
    }

    /** Close runs whose queue has drained but which never got their final tick. */
    public function closeStaleRuns(\DateTimeImmutable $olderThan): int
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE location_enrichment_run
                SET status = :complete, finished_at = :now
              WHERE status = :running
                AND started_at < :olderThan',
            [
                'complete'  => EnrichmentRunStatus::COMPLETE->value,
                'running'   => EnrichmentRunStatus::RUNNING->value,
                'now'       => new \DateTimeImmutable(),
                'olderThan' => $olderThan,
            ],
            ['now' => 'datetime_immutable', 'olderThan' => 'datetime_immutable'],
        );
    }

    /** Undrained messages on the enrichment transport — the stacked-sweep guard. */
    public function countUndeliveredOnQueue(string $queueName): int
    {
        try {
            return (int) $this->getEntityManager()->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue AND delivered_at IS NULL',
                ['queue' => $queueName],
                ['queue' => ParameterType::STRING],
            );
        } catch (\Throwable) {
            // Same swallow as StarterPoolAuditService: the table may not exist before the
            // first dispatch, and a missing guard must not block an admin action.
            return 0;
        }
    }
}
