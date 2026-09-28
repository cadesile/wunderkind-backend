<?php

namespace App\Repository\WorldPack;

use App\Entity\WorldPack\WorldPackGenerationRun;
use App\Entity\WorldPack\WorldPackGenerationTierRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorldPackGenerationTierRun>
 */
class WorldPackGenerationTierRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorldPackGenerationTierRun::class);
    }

    /** @return list<WorldPackGenerationTierRun> */
    public function findByRun(WorldPackGenerationRun $run): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.run = :run')
            ->setParameter('run', $run->getId())
            ->orderBy('t.tier', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
