<?php

namespace App\Repository;

use App\Entity\Admin;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AdminRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Admin::class);
    }

    public function findByUser(User $user): ?Admin
    {
        return $this->findOneBy(['user' => $user]);
    }
}
