<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Club;
use App\Entity\SyncRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
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
     * Returns the `payload` + `serverTimestamp` + the club's real name for valid syncs
     * received since $since, for the landing page's "Chairman's Terminal" telemetry
     * aggregate. clubName is attached so buildLedgerEvents() can name the spending club
     * (safe per the same curated-name-options reasoning as findTopAttendanceSince() —
     * no moderation risk). Joins to club (previously payload+serverTimestamp only) —
     * still cheap over idx_sync_record_server_timestamp since it's a single indexed FK.
     *
     * @return array<int, array{payload: array<string, mixed>, serverTimestamp: \DateTimeImmutable, clubName: string}>
     */
    public function findValidPayloadsSince(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.payload', 's.serverTimestamp', 'c.name AS clubName')
            ->innerJoin('s.club', 'c')
            ->where('s.isValid = true')
            ->andWhere('s.serverTimestamp >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'payload'         => $row['payload'],
                'serverTimestamp' => $row['serverTimestamp'],
                'clubName'        => (string) $row['clubName'],
            ],
            $rows,
        );
    }

    /**
     * Top valid syncs in the window by attendance fanCount, with the club's real name
     * attached. Club names here come from a curated, server-generated set of options
     * (see /api/club/name-options) rather than free text, so this carries no
     * moderation risk despite naming a specific club — unlike SeasonRecordRepository's
     * anonymised events, which avoid the club entirely for other reasons (see there).
     *
     * @return array<int, array{clubName: string, fanCount: int, serverTimestamp: \DateTimeImmutable}>
     */
    public function findTopAttendanceSince(\DateTimeImmutable $since, int $limit): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT c.name AS club_name,
                    (sr.payload->'attendance'->>'fanCount')::int AS fan_count,
                    sr.server_timestamp AS server_timestamp
             FROM sync_record sr
             JOIN club c ON c.id = sr.club_id
             WHERE sr.is_valid = true
               AND sr.server_timestamp >= :since
               AND (sr.payload->'attendance'->>'fanCount') IS NOT NULL
             ORDER BY fan_count DESC
             LIMIT :limit",
            ['since' => $since->format('Y-m-d H:i:sP'), 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(
            static fn (array $row): array => [
                'clubName'        => (string) $row['club_name'],
                'fanCount'        => (int) $row['fan_count'],
                'serverTimestamp' => new \DateTimeImmutable($row['server_timestamp']),
            ],
            $rows,
        );
    }

    /**
     * Count of distinct clubs with a valid sync since $since — "active clubs" for the
     * landing page footer, matching the same DISTINCT club_id definition the admin
     * dashboard's "Active Clubs" KPI uses (see DashboardStatsService::activeClubs()).
     */
    public function countActiveClubsSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(DISTINCT IDENTITY(s.club))')
            ->where('s.isValid = true')
            ->andWhere('s.serverTimestamp >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
