<?php

namespace App\Repository;

use App\Entity\Academy;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AcademyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Academy::class);
    }

    public function findByUser(User $user): ?Academy
    {
        return $this->findOneBy(['user' => $user]);
    }
}
