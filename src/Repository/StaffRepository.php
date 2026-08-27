<?php

namespace App\Repository;

use App\Entity\Staff;
use App\Enum\StaffRole;
use App\Service\Admin\StatBuckets;
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
        $qb = $this->createQueryBuilder('s');

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
            ->select('COUNT(s.id)');

        if ($role !== null) {
            $qb->andWhere('s.role = :role')->setParameter('role', $role);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countInPoolByNationalityAndRole(string $nationality, StaffRole $role): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.nationality = :nat')
            ->andWhere('s.role = :role')
            ->setParameter('nat', $nationality)
            ->setParameter('role', $role)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Random pool draw filtered by exact role. Used by WorldInitializationService.
     * @return Staff[]
     */
    public function findInPoolByRoleRandom(StaffRole $role, int $limit, ?string $nationality = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.role = :role')
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

    /**
     * Pool breakdown for the admin dashboard. Same shape as
     * {@see \App\Repository\PlayerRepository::getPoolBreakdown()}, nested on role
     * rather than nationality — role is the axis that actually differentiates staff.
     *
     * @return array{total: int, facets: array<string, list<array{key: string, count: int}>>, nested: array{dimension: string, children: list<string>, rows: list<array<string, mixed>>}}
     */
    public function getPoolBreakdown(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.role AS role', 's.nationality AS nationality', 's.dob AS dob', 's.coachingAbility AS ability', 's.scoutingRange AS scouting')
            ->getQuery()
            ->getArrayResult();

        $now = new \DateTimeImmutable();

        $roles       = [];
        $nationality = [];
        $age         = StatBuckets::seed(StatBuckets::AGE_LABELS) + [StatBuckets::UNKNOWN => 0];
        $ability     = StatBuckets::seed(StatBuckets::ABILITY_LABELS);
        $scouting    = StatBuckets::seed(StatBuckets::ABILITY_LABELS);
        $nested      = [];

        foreach ($rows as $row) {
            $role = $row['role'] instanceof StaffRole ? $row['role']->value : (string) $row['role'];
            $nat  = (string) ($row['nationality'] ?? StatBuckets::UNKNOWN);

            $ageBucket     = StatBuckets::age($row['dob'] instanceof \DateTimeInterface ? $row['dob'] : null, $now);
            $abilityBucket = StatBuckets::ability((int) $row['ability']);

            $roles[$role]      = ($roles[$role] ?? 0) + 1;
            $nationality[$nat] = ($nationality[$nat] ?? 0) + 1;
            $age[$ageBucket]   = ($age[$ageBucket] ?? 0) + 1;
            $ability[$abilityBucket]++;
            $scouting[StatBuckets::ability((int) $row['scouting'])]++;

            $nested[$role]['ability'][$abilityBucket] = ($nested[$role]['ability'][$abilityBucket] ?? 0) + 1;
            $nested[$role]['nationality'][$nat]       = ($nested[$role]['nationality'][$nat] ?? 0) + 1;
            $nested[$role]['age'][$ageBucket]         = ($nested[$role]['age'][$ageBucket] ?? 0) + 1;
        }

        arsort($roles);
        arsort($nationality);

        return [
            'total'  => count($rows),
            'facets' => [
                'role'           => StatBuckets::facet($roles),
                'ability'        => StatBuckets::facet($ability),
                'nationality'    => StatBuckets::facet($nationality),
                'age'            => StatBuckets::facet($age),
                'scoutingRange'  => StatBuckets::facet($scouting),
            ],
            'nested' => StatBuckets::nested('role', $roles, $nested, ['ability', 'nationality', 'age']),
        ];
    }
}
