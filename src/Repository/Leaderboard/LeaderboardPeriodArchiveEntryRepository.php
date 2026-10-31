<?php

declare(strict_types=1);

namespace App\Repository\Leaderboard;

use App\Entity\Leaderboard\LeaderboardPeriodArchive;
use App\Entity\Leaderboard\LeaderboardPeriodArchiveEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeaderboardPeriodArchiveEntry>
 */
class LeaderboardPeriodArchiveEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaderboardPeriodArchiveEntry::class);
    }

    /**
     * The frozen board for one archive, in rank order.
     *
     * @return LeaderboardPeriodArchiveEntry[]
     */
    public function findForArchive(LeaderboardPeriodArchive $archive, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.archive = :archive')
            ->setParameter('archive', $archive)
            ->orderBy('e.rank', 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Top $perArchive entries for several archives at once, keyed by archive id.
     *
     * BATCHED for the same reason findByEntrants() / findBothSides() exist in the
     * competition code: a split must not trade a snapshot for an N+1 of the same size. The
     * landing page renders every listed board at once.
     *
     * The per-archive slice is applied in PHP rather than with a window function, because
     * the input is a handful of archives and each is already bounded to
     * ARCHIVED_ENTRY_LIMIT rows by construction.
     *
     * @param  string[] $archiveIds
     * @return array<string, LeaderboardPeriodArchiveEntry[]>
     */
    public function findTopEntriesForArchives(array $archiveIds, int $perArchive = 5): array
    {
        if ($archiveIds === []) {
            return [];
        }

        /** @var LeaderboardPeriodArchiveEntry[] $rows */
        $rows = $this->createQueryBuilder('e')
            ->where('e.archive IN (:archiveIds)')
            ->setParameter('archiveIds', $archiveIds)
            ->orderBy('e.rank', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($rows as $row) {
            $key = (string) $row->getArchive()->getId();

            if (count($grouped[$key] ?? []) >= $perArchive) {
                continue;
            }

            $grouped[$key][] = $row;
        }

        return $grouped;
    }

    /**
     * Every archive row that credited this account — the "awards won by this account"
     * read, served by idx_lpae_user.
     *
     * @return LeaderboardPeriodArchiveEntry[]
     */
    public function findAwardedForUser(string $userId): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.archive', 'a')
            ->addSelect('a')
            ->where('e.userId = :userId')
            ->andWhere('e.awarded = true')
            ->setParameter('userId', $userId)
            ->orderBy('a.closedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Discards a pending archive's partial entries before a reclaimed close rewrites them.
     *
     * Raw DBAL and wholesale: a pending archive's entries are by definition incomplete, and
     * deleting them is exactly what lets the entry write use the plain ORM on a retry
     * without tripping uq_lpae_archive_rank.
     */
    public function deleteForArchiveId(string $archiveId): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM leaderboard_period_archive_entry WHERE archive_id = :archiveId',
            ['archiveId' => $archiveId],
        );
    }

    /** Account deletion: retain the rank, forget the account. See AccountDeletionService. */
    public function forgetUser(string $userId): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE leaderboard_period_archive_entry SET user_id = NULL WHERE user_id = :userId',
            ['userId' => $userId],
        );
    }
}
