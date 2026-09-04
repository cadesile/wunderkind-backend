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
        // Ordered by id, deliberately. PoolConfig is a singleton (no country/tier
        // discriminator), but nothing enforces that at the schema level, and an
        // unordered findOneBy([]) returns whichever row PostgreSQL yields first from
        // the heap. That is not stable across writes: an UPDATE rewrites the tuple at a
        // new heap location, so the row read after a save can differ from the row that
        // was saved. In the admin that presented as the Player Pool Config form
        // resetting to defaults on save.
        $config = $this->findOneBy([], ['id' => 'ASC']);
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
