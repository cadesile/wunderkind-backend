<?php

namespace App\Repository;

use App\Entity\PoolConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PoolConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PoolConfig::class);
    }

    /**
     * Returns the single PoolConfig row, creating it with defaults if absent.
     * Pass flush: true when you need the row persisted immediately.
     */
    public function getConfig(bool $flush = false): PoolConfig
    {
        $config = $this->findOneBy([]);
        if ($config === null) {
            $config = new PoolConfig();
            $this->getEntityManager()->persist($config);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        }
        return $config;
    }
}
