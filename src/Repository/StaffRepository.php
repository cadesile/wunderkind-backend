<?php

namespace App\Repository;

use App\Entity\Club;
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
     * Random sample of staff from the market pool, optionally filtered by role and coaching ability range.
     * @return Staff[]
     */
    public function findInPool(?StaffRole $role = null, int $limit = 20, ?int $abilityMin = null, ?int $abilityMax = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.club IS NULL');

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

        $results = $qb->getQuery()->getResult();
        shuffle($results);
        return array_slice($results, 0, $limit);
    }

    public function countInPool(?StaffRole $role = null): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.club IS NULL');

        if ($role !== null) {
            $qb->andWhere('s.role = :role')->setParameter('role', $role);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countInPoolByNationalityAndRole(string $nationality, StaffRole $role): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.club IS NULL')
            ->andWhere('s.nationality = :nat')
            ->andWhere('s.role = :role')
            ->setParameter('nat', $nationality)
            ->setParameter('role', $role)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Staff[] */
    public function findByClub(Club $club): array
    {
        return $this->findBy(['club' => $club]);
    }

    /**
     * Random pool draw filtered by exact role. Used by WorldInitializationService.
     * @return Staff[]
     */
    public function findInPoolByRoleRandom(StaffRole $role, int $limit, ?string $nationality = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.club IS NULL')
            ->andWhere('s.role = :role')
            ->setParameter('role', $role);

        if ($nationality !== null) {
            $qb->andWhere('s.nationality = :nationality')->setParameter('nationality', $nationality);
        }

        $results = $qb->getQuery()->getResult();
        shuffle($results);
        return array_slice($results, 0, $limit);
    }

    /**
     * Bulk-delete staff by UUID array. Used after world-init dispatch.
     * @param string[] $ids
     */
    public function deleteByIds(array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }
}
