<?php

namespace App\Repository\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionResponseCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\UuidV7;

/**
 * Reads and writes `competition_response_cache` through DBAL, never the ORM unit of work.
 *
 * The entity stays mapped so Doctrine owns the table's schema, but nothing hydrates it. Two
 * concrete bugs made that necessary:
 *
 *  - **A failed `flush()` closes the EntityManager.** When the cache row was written through
 *    the ORM, a failure on that write (an FK violation because the competition was deleted
 *    mid-tick, a unique violation after the advisory lock fell through, a statement timeout
 *    on the large JSON) closed the EntityManager shared with the caller. In a cron tick that
 *    meant the *next* due round was claimed by raw SQL and then died on
 *    `EntityManagerClosed` — leaving `resolve_locked_at` set with no way for any later tick
 *    to reclaim it. A `catch (\Throwable)` around the cache write does not undo that.
 *  - **The identity map hid other writers.** `findOneBy()` returns an already-managed
 *    instance without re-hydrating it, so the freshness re-check inside the advisory lock
 *    could not see the rebuild another request had just committed, and every queued request
 *    rebuilt the whole bracket in turn.
 *
 * Reading raw rows fixes both: a cache failure cannot touch the caller's EntityManager, and
 * every read sees what is actually in the table.
 *
 * @extends ServiceEntityRepository<CompetitionResponseCache>
 */
class CompetitionResponseCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitionResponseCache::class);
    }

    /**
     * The stored row as raw columns, or null when nothing is cached.
     *
     * @return array{payload: string, state_fingerprint: string, payload_version: int}|null
     */
    public function findRow(ActiveCompetition $activeCompetition): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT payload, state_fingerprint, payload_version FROM competition_response_cache WHERE active_competition_id = :id',
            ['id' => $activeCompetition->getId()->toRfc4122()],
        );

        if ($row === false) {
            return null;
        }

        return [
            'payload'         => (string) $row['payload'],
            'state_fingerprint' => (string) $row['state_fingerprint'],
            'payload_version' => (int) $row['payload_version'],
        ];
    }

    /**
     * Stores the document, replacing whatever was there.
     *
     * `ON CONFLICT` rather than a read-then-insert: the unique index on
     * `active_competition_id` is what makes a concurrent write collapse into an update
     * instead of an error, which is also why the advisory lock falling through unlocked is
     * survivable rather than a unique violation.
     *
     * @param array<string, mixed> $payload
     */
    public function upsert(ActiveCompetition $activeCompetition, array $payload, string $stateFingerprint): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
            INSERT INTO competition_response_cache
                (id, active_competition_id, payload, state_fingerprint, payload_version, generated_at)
            VALUES (:id, :competitionId, :payload, :stateFingerprint, :payloadVersion, :generatedAt)
            ON CONFLICT (active_competition_id) DO UPDATE
                SET payload         = EXCLUDED.payload,
                    state_fingerprint = EXCLUDED.state_fingerprint,
                    payload_version = EXCLUDED.payload_version,
                    generated_at    = EXCLUDED.generated_at
            SQL,
            [
                'id'             => (new UuidV7())->toRfc4122(),
                'competitionId'  => $activeCompetition->getId()->toRfc4122(),
                'payload'        => json_encode($payload, JSON_THROW_ON_ERROR),
                'stateFingerprint' => $stateFingerprint,
                'payloadVersion' => CompetitionResponseCache::PAYLOAD_VERSION,
                'generatedAt'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function deleteForCompetition(ActiveCompetition $activeCompetition): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM competition_response_cache WHERE active_competition_id = :competitionId',
            ['competitionId' => $activeCompetition->getId()->toRfc4122()],
        );
    }

    /**
     * Competition ids whose cached document is built from a superseded payload shape.
     *
     * What `app:competition:cache:warm --stale-only` rebuilds after a deploy that changes the
     * serializer: those rows are not wrong so much as unreadable by the current code, and
     * would otherwise be rebuilt one at a time by whichever request arrived first.
     *
     * @return list<string>
     */
    public function findStalePayloadVersionIds(int $currentPayloadVersion): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT active_competition_id FROM competition_response_cache WHERE payload_version <> :version',
            ['version' => $currentPayloadVersion],
        );

        return array_map('strval', $rows);
    }
}
