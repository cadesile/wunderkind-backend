<?php

namespace App\Repository;

use App\Entity\GameConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class GameConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameConfig::class);
    }

    /**
     * Returns the single GameConfig row, creating it with defaults if absent.
     * Pass flush: true when you need the row persisted immediately.
     */
    public function getConfig(bool $flush = false): GameConfig
    {
        $config = $this->findOneBy([]);
        if ($config === null) {
            $config = new GameConfig();
            $this->getEntityManager()->persist($config);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        }
        return $config;
    }
}
