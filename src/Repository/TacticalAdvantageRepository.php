<?php

namespace App\Repository;

use App\Entity\TacticalAdvantage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TacticalAdvantageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TacticalAdvantage::class);
    }

    /**
     * @return TacticalAdvantage[]
     */
    public function findAllAsArray(): array
    {
        return $this->findAll();
    }
}
