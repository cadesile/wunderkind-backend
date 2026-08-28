<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeletionRequest;
use App\Enum\DeletionRequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeletionRequest>
 */
class DeletionRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeletionRequest::class);
    }

    /**
     * Failed credential attempts from one IP inside the window.
     *
     * Deliberately counts only REJECTED_INVALID_CREDENTIALS. Brute-forcing a
     * password is precisely a run of those, whereas a completed deletion is the
     * flow working as intended — letting successes consume the budget would
     * throttle legitimate users (and anyone sharing an IP behind carrier-grade
     * NAT) for doing nothing wrong.
     */
    public function countRecentFailuresByIp(string $ipAddress, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.ipAddress = :ip')
            ->andWhere('d.requestedAt >= :since')
            ->andWhere('d.status = :status')
            ->setParameter('ip', $ipAddress)
            ->setParameter('since', $since)
            ->setParameter('status', DeletionRequestStatus::REJECTED_INVALID_CREDENTIALS)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Rows older than the retention cut-off, for the purge command. */
    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.requestedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
