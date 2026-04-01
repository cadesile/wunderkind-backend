<?php

namespace App\Repository;

use App\Entity\Academy;
use App\Entity\Staff;
use App\Enum\StaffRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class StaffRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Staff::class);
    }

    /**
     * Staff with no academy (market pool), optionally filtered by role and coaching ability range.
     * @return Staff[]
     */
    public function findInPool(?StaffRole $role = null, int $limit = 20, ?int $abilityMin = null, ?int $abilityMax = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.academy IS NULL')
            ->orderBy('s.hiredAt', 'DESC')
            ->setMaxResults($limit);

        if ($role !== null) {
            $qb->andWhere('s.role = :role')->setParameter('role', $role);
        }

        if ($abilityMin !== null) {
            $qb->andWhere('s.coachingAbility >= :abilityMin')
               ->setParameter('abilityMin', $abilityMin);
        }

        if ($abilityMax !== null) {
            $qb->andWhere('s.coachingAbility <= :abilityMax')
               ->setParameter('abilityMax', $abilityMax);
        }

        return $qb->getQuery()->getResult();
    }

    public function countInPool(?StaffRole $role = null): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.academy IS NULL');

        if ($role !== null) {
            $qb->andWhere('s.role = :role')->setParameter('role', $role);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return Staff[] */
    public function findByAcademy(Academy $academy): array
    {
        return $this->findBy(['academy' => $academy]);
    }
}
