<?php

namespace App\Repository;

use App\Entity\ClubSpotlight;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ClubSpotlightRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClubSpotlight::class);
    }

    /**
     * Returns the single ClubSpotlight row, creating an empty one if absent
     * (e.g. before the first app:spotlight:generate cron run).
     */
    public function getCurrent(bool $flush = false): ClubSpotlight
    {
        $spotlight = $this->findOneBy([]);
        if ($spotlight === null) {
            $spotlight = new ClubSpotlight();
            $this->getEntityManager()->persist($spotlight);
            if ($flush) {
                $this->getEntityManager()->flush();
            }
        }
        return $spotlight;
    }
}
