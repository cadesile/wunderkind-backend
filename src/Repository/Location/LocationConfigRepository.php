<?php

declare(strict_types=1);

namespace App\Repository\Location;

use App\Entity\Location\LocationConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LocationConfig>
 */
class LocationConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LocationConfig::class);
    }

    /**
     * The single config row, created with defaults if absent.
     *
     * ORDERED BY id, copying PoolConfigRepository::getConfig() for the reason its comment
     * gives: nothing enforces the single row at schema level, and an unordered findOneBy([])
     * returns whichever row PostgreSQL yields from the heap — which is not stable across
     * writes, because an UPDATE rewrites the tuple at a new location. That presented as the
     * Player Pool Config form appearing to reset to defaults on save, and there is no reason
     * to re-learn it here.
     */
    public function getConfig(bool $flush = false): LocationConfig
    {
        $config = $this->findOneBy([], ['id' => 'ASC']);

        if ($config === null) {
            $config = new LocationConfig();
            $this->getEntityManager()->persist($config);

            if ($flush) {
                $this->getEntityManager()->flush();
            }
        }

        return $config;
    }
}
