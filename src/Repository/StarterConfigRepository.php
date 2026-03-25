<?php

namespace App\Repository;

use App\Entity\StarterConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class StarterConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StarterConfig::class);
    }

    /**
     * Returns the single StarterConfig row (id = 1).
     * If the row is absent (fresh install before seeder migration has run),
     * returns an in-memory default without persisting.
     */
    public function getConfig(): StarterConfig
    {
        return $this->find(1) ?? StarterConfig::defaults();
    }
}
