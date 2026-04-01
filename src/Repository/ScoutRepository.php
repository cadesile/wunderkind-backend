<?php

namespace App\Repository;

use App\Entity\Scout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ScoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Scout::class);
    }

    /**
     * @return Scout[]
     * @param int|null $experienceMin If provided, only scouts with experience >= this value
     * @param int|null $experienceMax If provided, only scouts with experience <= this value
     */
    public function findInPool(int $limit = 10, ?int $experienceMin = null, ?int $experienceMax = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->orderBy('s.id', 'DESC')
            ->setMaxResults($limit);

        if ($experienceMin !== null) {
            $qb->andWhere('s.experience >= :expMin')->setParameter('expMin', $experienceMin);
        }

        if ($experienceMax !== null) {
            $qb->andWhere('s.experience <= :expMax')->setParameter('expMax', $experienceMax);
        }

        return $qb->getQuery()->getResult();
    }
}
