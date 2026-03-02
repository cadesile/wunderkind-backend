<?php

namespace App\Repository;

use App\Entity\Sponsor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SponsorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sponsor::class);
    }

    /** @return Sponsor[] Active sponsors not yet assigned to an academy */
    public function findInPool(int $limit = 20): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.isActive = true')
            ->andWhere('s.academy IS NULL')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countInPool(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.isActive = true')
            ->andWhere('s.academy IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Sponsor[] All active sponsors (pool + assigned) */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true]);
    }
}
