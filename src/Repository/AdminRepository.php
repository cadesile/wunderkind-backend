<?php

namespace App\Repository;

use App\Entity\Admin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AdminRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Admin::class);
    }

    public function findByEmail(string $email): ?Admin
    {
        return $this->findOneBy(['email' => $email]);
    }
}
