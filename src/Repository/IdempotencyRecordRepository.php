<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IdempotencyRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IdempotencyRecord>
 */
class IdempotencyRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IdempotencyRecord::class);
    }

    public function findActiveRecord(string $userId, string $method, string $path, string $idempotencyKey): ?IdempotencyRecord
    {
        $cutoff = (new \DateTimeImmutable())->modify('-24 hours');

        return $this->createQueryBuilder('r')
            ->where('r.userId = :userId')
            ->andWhere('r.method = :method')
            ->andWhere('r.path = :path')
            ->andWhere('r.idempotencyKey = :idempotencyKey')
            ->andWhere('r.createdAt >= :cutoff')
            ->setParameter('userId', $userId)
            ->setParameter('method', $method)
            ->setParameter('path', $path)
            ->setParameter('idempotencyKey', $idempotencyKey)
            ->setParameter('cutoff', $cutoff)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
