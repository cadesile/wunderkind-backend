<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Club;
use App\Entity\SyncRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
