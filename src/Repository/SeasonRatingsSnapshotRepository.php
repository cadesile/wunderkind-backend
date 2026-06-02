<?php

namespace App\Repository;

use App\Entity\SeasonRatingsSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SeasonRatingsSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeasonRatingsSnapshot::class);
    }

    /** @return SeasonRatingsSnapshot[] */
    public function findBySeasonAndTier(int $season, int $tier): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.season = :season')
            ->andWhere('s.tier = :tier')
            ->setParameter('season', $season)
            ->setParameter('tier', $tier)
            ->orderBy('s.expectedPosition', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
