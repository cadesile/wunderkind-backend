<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Club;
use App\Entity\SyncRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SyncRecord>
 */
class SyncRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncRecord::class);
    }

    /**
     * Deletes all sync records for a club where clientWeekNumber >= $fromWeek.
     * Called on rollback to discard future-state records that are now superseded.
     */
    public function deleteByClubFromWeek(Club $club, int $fromWeek): int
    {
        return (int) $this->getEntityManager()
            ->createQueryBuilder()
            ->delete(SyncRecord::class, 's')
            ->where('s.club = :club')
            ->andWhere('s.clientWeekNumber >= :fromWeek')
            ->setParameter('club', $club)
            ->setParameter('fromWeek', $fromWeek)
            ->getQuery()
            ->execute();
    }

    /** Returns the total rollback count for a club (used for player score penalties). */
    public function countRollbacksByClub(Club $club): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.club = :club')
            ->andWhere('s.isRollback = true')
            ->setParameter('club', $club)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns just the `payload` column for valid syncs received since $since, for the
     * landing page's "Chairman's Terminal" telemetry aggregate. Selecting only the JSON
     * column (not full entities) keeps this cheap over the idx_sync_record_server_timestamp
     * index even as the table grows.
     *
     * getSingleColumnResult() bypasses Doctrine's `json` type conversion (that only runs
     * during entity hydration), so each row comes back as a raw JSON string — decode it
     * here rather than leaking that detail to the caller.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findValidPayloadsSince(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.payload')
            ->where('s.isValid = true')
            ->andWhere('s.serverTimestamp >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(
            static fn (string $json): array => json_decode($json, true) ?? [],
            $rows,
        );
    }

    /**
     * Latest valid payload per club, for syncs at or after $since. This is the "now" side
     * of a per-club seasonRecord delta (see LiveTelemetryService::aggregateSeasonActivity) —
     * real clients don't currently populate matchResults[], so fixtures/goals are derived
     * from the change in each club's cumulative seasonRecord instead.
     *
     * @return array<string, array<string, mixed>> payload keyed by club id
     */
    public function findLatestValidPayloadPerClubSince(\DateTimeImmutable $since): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT ON (club_id) club_id, payload
             FROM sync_record
             WHERE is_valid = true AND server_timestamp >= :since
             ORDER BY club_id, server_timestamp DESC',
            ['since' => $since->format('Y-m-d H:i:sP')],
        );

        return self::payloadsByClubId($rows);
    }

    /**
     * Latest valid payload per club, for syncs strictly before $before — the "baseline"
     * side of the delta. Only looked up for the clubs that actually appear in the window,
     * not the whole table.
     *
     * @param string[] $clubIds
     * @return array<string, array<string, mixed>> payload keyed by club id
     */
    public function findLatestValidPayloadPerClubBefore(\DateTimeImmutable $before, array $clubIds): array
    {
        if ($clubIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DISTINCT ON (club_id) club_id, payload
             FROM sync_record
             WHERE is_valid = true AND server_timestamp < :before AND club_id IN (:clubIds)
             ORDER BY club_id, server_timestamp DESC',
            ['before' => $before->format('Y-m-d H:i:sP'), 'clubIds' => $clubIds],
            ['clubIds' => ArrayParameterType::STRING],
        );

        return self::payloadsByClubId($rows);
    }

    /** @return array<string, array<string, mixed>> */
    private static function payloadsByClubId(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[$row['club_id']] = json_decode($row['payload'], true) ?? [];
        }

        return $result;
    }
}
