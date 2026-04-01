<?php

namespace App\Repository;

use App\Entity\Agent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AgentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agent::class);
    }

    /**
     * @return Agent[]
     * @param int|null $ratingMin If provided, only agents with rating >= this value
     * @param int|null $ratingMax If provided, only agents with rating <= this value
     */
    public function findInPool(int $limit = 20, ?int $ratingMin = null, ?int $ratingMax = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.id', 'DESC')
            ->setMaxResults($limit);

        if ($ratingMin !== null) {
            $qb->andWhere('a.rating >= :ratingMin')->setParameter('ratingMin', $ratingMin);
        }

        if ($ratingMax !== null) {
            $qb->andWhere('a.rating <= :ratingMax')->setParameter('ratingMax', $ratingMax);
        }

        return $qb->getQuery()->getResult();
    }
}
