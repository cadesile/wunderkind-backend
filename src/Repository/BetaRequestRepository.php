<?php

namespace App\Repository;

use App\Entity\BetaRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BetaRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BetaRequest::class);
    }

    /** Most recent unexpired, unverified record for an email */
    public function findActiveByEmail(string $email): ?BetaRequest
    {
        return $this->createQueryBuilder('b')
            ->where('b.email = :email')
            ->andWhere('b.valid = false')
            ->andWhere('b.expiresAt > :now')
            ->setParameter('email', $email)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Most recent record (any state) for an email — used for lockout/expiry messaging */
    public function findLatestByEmail(string $email): ?BetaRequest
    {
        return $this->createQueryBuilder('b')
            ->where('b.email = :email')
            ->setParameter('email', $email)
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
