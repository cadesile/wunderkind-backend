<?php

namespace App\Repository;

use App\Entity\Investor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InvestorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Investor::class);
    }

    /** @return Investor[] */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true]);
    }
}
