<?php

namespace App\Repository;

use App\Entity\Investor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InvestorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Investor::class);
    }

    /** @return Investor[] Active investors not yet assigned to an academy */
    public function findInPool(int $limit = 10): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.isActive = true')
            ->andWhere('i.academy IS NULL')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countInPool(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.isActive = true')
            ->andWhere('i.academy IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Investor[] All active investors (pool + assigned) */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true]);
    }
}
