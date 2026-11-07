<?php

declare(strict_types=1);

namespace App\Repository\Location;

use App\Entity\Location\TownSuggestionBatch;
use App\Enum\Location\TownSuggestionBatchStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TownSuggestionBatch>
 *
 * The write methods here are raw SQL on purpose. The handler calls them either side of
 * LocationSeedService::importNames(), which ends in `em->clear()` when it imported anything —
 * so any entity held across it is detached, and an ORM write afterwards is lost without
 * raising. Raw statements are immune, which lets the handler follow one rule (hold nothing)
 * rather than two.
 */
class TownSuggestionBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TownSuggestionBatch::class);
    }

    /** @return list<TownSuggestionBatch> in display order, which is batch order */
    public function findForRun(string $runId): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.run = :run')
            ->setParameter('run', $runId)
            ->orderBy('b.batchIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every name already offered by any OTHER batch of this run.
     *
     * The exclusion input for the batch about to run, and the reason TownSuggestionBatch stores
     * names at all. Without it each batch asks the model the same question — "the fifty biggest
     * towns you don't already hold" — and the only thing that would have changed the answer is
     * this list, because freshly imported towns have no population and so sort into the tail of
     * the held-towns list that the prompt truncates away.
     *
     * Deliberately ALL other batches, not only lower indexes: a batch that self-retried runs
     * after ones that come later in display order, and excluding more is never wrong.
     *
     * @return list<string>
     */
    public function offeredNamesForOtherBatches(string $runId, string $exceptBatchId): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT offered_names
               FROM town_suggestion_batch
              WHERE run_id = :run
                AND id <> :except
                AND offered_names IS NOT NULL',
            ['run' => $runId, 'except' => $exceptBatchId],
        );

        $names = [];

        foreach ($rows as $row) {
            $decoded = json_decode((string) $row, true);

            if (!\is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $name) {
                if (\is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }

        // Case-insensitive dedupe: the same town offered by two batches is one exclusion.
        $seen   = [];
        $unique = [];

        foreach ($names as $name) {
            $key = mb_strtolower($name);

            if (!isset($seen[$key])) {
                $seen[$key]  = true;
                $unique[]    = $name;
            }
        }

        return $unique;
    }

    /** Mark a batch as in flight, and count the attempt. Raw: nothing may be held hydrated. */
    public function markRunning(string $batchId, int $attempt): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE town_suggestion_batch
                SET status = :running, attempts = :attempt, started_at = COALESCE(started_at, :now)
              WHERE id = :id',
            [
                'running' => TownSuggestionBatchStatus::RUNNING->value,
                'attempt' => $attempt,
                'now'     => new \DateTimeImmutable(),
                'id'      => $batchId,
            ],
            ['now' => 'datetime_immutable'],
        );
    }

    /**
     * Record what the model offered, the moment it answers.
     *
     * SEPARATE FROM, AND BEFORE, markCompleted() on purpose. These names are what every other
     * batch of the run excludes, so they must survive a batch that answers and then fails on
     * the import — otherwise a sibling re-asks for them and the run pays twice for one answer.
     * Writing them when they are known rather than when the batch finishes is the difference.
     *
     * @param list<string> $names
     */
    public function recordOffered(string $batchId, int $offered, array $names): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE town_suggestion_batch
                SET offered = :offered, offered_names = :names
              WHERE id = :id',
            [
                'offered' => $offered,
                'names'   => json_encode(array_values($names)),
                'id'      => $batchId,
            ],
        );
    }

    /** Record what a batch achieved. Offered names are already stored by recordOffered(). */
    public function markCompleted(
        string $batchId,
        int $accepted,
        int $imported,
        int $alreadyHeld,
    ): void {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE town_suggestion_batch
                SET status = :completed,
                    accepted = :accepted,
                    imported = :imported,
                    already_held = :alreadyHeld,
                    error_message = NULL,
                    finished_at = :now
              WHERE id = :id',
            [
                'completed'   => TownSuggestionBatchStatus::COMPLETED->value,
                'accepted'    => $accepted,
                'imported'    => $imported,
                'alreadyHeld' => $alreadyHeld,
                'now'         => new \DateTimeImmutable(),
                'id'          => $batchId,
            ],
            ['now' => 'datetime_immutable'],
        );
    }

    /** Terminal failure — the handler has exhausted its own retries. */
    public function markFailed(string $batchId, string $error): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE town_suggestion_batch
                SET status = :failed, error_message = :error, finished_at = :now
              WHERE id = :id',
            [
                'failed' => TownSuggestionBatchStatus::FAILED->value,
                'error'  => mb_substr($error, 0, 2000),
                'now'    => new \DateTimeImmutable(),
                'id'     => $batchId,
            ],
            ['now' => 'datetime_immutable'],
        );
    }

    /**
     * Retryable failure — record why, but leave the batch pending for the re-dispatch.
     *
     * Status goes back to PENDING rather than staying RUNNING so the progress UI reads
     * "waiting" instead of showing a batch that looks stuck mid-call for the whole delay.
     */
    public function markRetrying(string $batchId, string $error): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE town_suggestion_batch
                SET status = :pending, error_message = :error
              WHERE id = :id',
            [
                'pending' => TownSuggestionBatchStatus::PENDING->value,
                'error'   => mb_substr($error, 0, 2000),
                'id'      => $batchId,
            ],
        );
    }

    /** Towns actually added by a run, summed from its batches. */
    public function importedForRun(string $runId): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(imported), 0) FROM town_suggestion_batch WHERE run_id = :run',
            ['run' => $runId],
        );
    }
}
