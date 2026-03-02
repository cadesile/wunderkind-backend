<?php

namespace App\Repository;

use App\Entity\Academy;
use App\Entity\Player;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PlayerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Player::class);
    }

    /** @return Player[] Players with no academy (market pool) */
    public function findInPool(int $limit = 100): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.academy IS NULL')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countInPool(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.academy IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Player[] */
    public function findByAcademy(Academy $academy): array
    {
        return $this->findBy(['academy' => $academy]);
    }
}
