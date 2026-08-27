<?php

namespace App\Repository;

use App\Entity\Guardian;
use App\Entity\Player;
use App\Enum\PlayerPosition;
use App\Enum\PlayerStatus;
use App\Service\Admin\StatBuckets;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PlayerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Player::class);
    }

    /**
     * @return Player[] All unassigned pool players, optionally filtered by nationality / ability.
     * @param int|null $abilityMin If provided, only players with currentAbility >= this value
     * @param int|null $abilityMax If provided, only players with currentAbility <= this value
     */
    public function findInPool(int $limit = 100, ?string $nationality = null, ?int $abilityMin = null, ?int $abilityMax = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($nationality !== null) {
            $qb->andWhere('p.nationality = :nationality')
               ->setParameter('nationality', $nationality);
        }

        if ($abilityMin !== null) {
            $qb->andWhere('p.currentAbility >= :abilityMin')
               ->setParameter('abilityMin', $abilityMin);
        }

        if ($abilityMax !== null) {
            $qb->andWhere('p.currentAbility <= :abilityMax')
               ->setParameter('abilityMax', $abilityMax);
        }

        return $qb->getQuery()->getResult();
    }

    public function countInPool(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countInPoolByNationality(string $nationality): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.nationality = :nat')
            ->setParameter('nat', $nationality)
            ->getQuery()
            ->getSingleScalarResult();
    }


    /**
     * Random pool draw filtered by ability range and position (any nationality).
     * Used to guarantee minimum positional quotas before the general draw.
     * @return Player[]
     */
    public function findForWorldInitByPosition(int $abilityMin, int $abilityMax, PlayerPosition $position, int $limit): array
    {
        if ($limit <= 0) return [];

        return $this->createQueryBuilder('p')
            ->addSelect('RAND() AS HIDDEN rand_order')
            ->where('p.currentAbility BETWEEN :min AND :max')
            ->andWhere('p.position = :position')
            ->setParameter('min', $abilityMin)
            ->setParameter('max', $abilityMax)
            ->setParameter('position', $position)
            ->setMaxResults($limit)
            ->orderBy('rand_order')
            ->getQuery()
            ->getResult();
    }

    /**
     * Random pool draw filtered by ability range and exact nationality.
     * Uses RANDOM() ordering via Doctrine DQL. For PostgreSQL only.
     * @return Player[]
     */
    public function findForWorldInit(int $abilityMin, int $abilityMax, string $nationality, int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('RAND() AS HIDDEN rand_order')
            ->where('p.currentAbility BETWEEN :min AND :max')
            ->andWhere('p.nationality = :nationality')
            ->setParameter('min', $abilityMin)
            ->setParameter('max', $abilityMax)
            ->setParameter('nationality', $nationality)
            ->setMaxResults($limit)
            ->orderBy('rand_order')
            ->getQuery()
            ->getResult();
    }

    /**
     * Random pool draw filtered by ability range, position, and exact nationality.
     * Used for position-weighted squad generation (domestic slot per position).
     *
     * @param string[] $excludeIds UUID strings of players already assigned in this build pass.
     * @return Player[]
     */
    public function findForWorldInitByPositionAndNationality(int $abilityMin, int $abilityMax, PlayerPosition $position, string $nationality, int $limit, array $excludeIds = []): array
    {
        if ($limit <= 0) return [];

        $qb = $this->createQueryBuilder('p')
            ->addSelect('RAND() AS HIDDEN rand_order')
            ->where('p.currentAbility BETWEEN :min AND :max')
            ->andWhere('p.position = :position')
            ->andWhere('p.nationality = :nationality')
            ->setParameter('min', $abilityMin)
            ->setParameter('max', $abilityMax)
            ->setParameter('position', $position)
            ->setParameter('nationality', $nationality)
            ->setMaxResults($limit)
            ->orderBy('rand_order');

        if (!empty($excludeIds)) {
            $qb->andWhere('p.id NOT IN (:excludeIds)')
               ->setParameter('excludeIds', $excludeIds);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Random pool draw filtered by ability range, position, excluding a nationality (foreign slot per position).
     * Pass '__none__' as $excludeNationality to draw from any nationality (backfill use case).
     *
     * @param string[] $excludeIds UUID strings of players already assigned in this build pass.
     * @return Player[]
     */
    public function findForeignForWorldInitByPosition(int $abilityMin, int $abilityMax, string $excludeNationality, PlayerPosition $position, int $limit, array $excludeIds = []): array
    {
        if ($limit <= 0) return [];

        $qb = $this->createQueryBuilder('p')
            ->addSelect('RAND() AS HIDDEN rand_order')
            ->where('p.currentAbility BETWEEN :min AND :max')
            ->andWhere('p.position = :position')
            ->andWhere('p.nationality != :nationality')
            ->setParameter('min', $abilityMin)
            ->setParameter('max', $abilityMax)
            ->setParameter('position', $position)
            ->setParameter('nationality', $excludeNationality)
            ->setMaxResults($limit)
            ->orderBy('rand_order');

        if (!empty($excludeIds)) {
            $qb->andWhere('p.id NOT IN (:excludeIds)')
               ->setParameter('excludeIds', $excludeIds);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Random pool draw filtered by ability range, excluding a nationality (for foreign players).
     * @return Player[]
     */
    public function findForeignForWorldInit(int $abilityMin, int $abilityMax, string $excludeNationality, int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('RAND() AS HIDDEN rand_order')
            ->where('p.currentAbility BETWEEN :min AND :max')
            ->andWhere('p.nationality != :nationality')
            ->setParameter('min', $abilityMin)
            ->setParameter('max', $abilityMax)
            ->setParameter('nationality', $excludeNationality)
            ->setMaxResults($limit)
            ->orderBy('rand_order')
            ->getQuery()
            ->getResult();
    }

    /**
     * Random pool draw for the scout search endpoint.
     * All parameters except ability range are optional filters.
     * Age is derived from dateOfBirth server-side so no PostgreSQL-specific SQL is needed.
     *
     * @return Player[]
     */
    public function findForScoutSearch(
        int $abilityMin,
        int $abilityMax,
        ?PlayerPosition $position,
        ?string $nationality,
        ?int $ageMin,
        ?int $ageMax,
        int $limit,
    ): array {
        if ($limit <= 0) return [];

        $qb = $this->createQueryBuilder('p')
            ->addSelect('RAND() AS HIDDEN rand_order')
            ->where('p.currentAbility BETWEEN :abilityMin AND :abilityMax')
            ->setParameter('abilityMin', $abilityMin)
            ->setParameter('abilityMax', $abilityMax)
            ->orderBy('rand_order')
            ->setMaxResults($limit);

        if ($position !== null) {
            $qb->andWhere('p.position = :position')
               ->setParameter('position', $position);
        }

        if ($nationality !== null) {
            $qb->andWhere('p.nationality = :nationality')
               ->setParameter('nationality', $nationality);
        }

        // Convert age bounds to dateOfBirth bounds (in PHP to avoid DQL date_sub limitations)
        $now = new \DateTimeImmutable();
        if ($ageMin !== null) {
            // age >= ageMin → dateOfBirth <= today - ageMin years
            $qb->andWhere('p.dateOfBirth <= :dobMax')
               ->setParameter('dobMax', $now->modify("-{$ageMin} years"));
        }
        if ($ageMax !== null) {
            // age <= ageMax → dateOfBirth >= today - ageMax years - 1 year + 1 day
            $qb->andWhere('p.dateOfBirth >= :dobMin')
               ->setParameter('dobMin', $now->modify("-{$ageMax} years")->modify('-1 year')->modify('+1 day'));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Bulk-delete players by UUID array. Used after world-init dispatch.
     * Guardians referencing these players are deleted first to satisfy the FK constraint.
     * @param string[] $ids
     */
    public function deleteByIds(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        // Delete guardians first — DQL bulk DELETE bypasses Doctrine cascade,
        // so the DB FK (guardian.player_id → player.id) must be cleared manually.
        $this->getEntityManager()
            ->createQueryBuilder()
            ->delete(Guardian::class, 'g')
            ->where('g.player IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();

        $this->createQueryBuilder('p')
            ->delete()
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    /**
     * Backwards-compatible view of {@see getPoolBreakdown()} for the Player CRUD index.
     *
     * @return array{byNationality: array<string,int>, byPosition: array<string,int>, byAge: array<string,int>, byAbility: array<string,int>}
     */
    public function getAdminSummary(): array
    {
        $breakdown = $this->getPoolBreakdown();

        return [
            'byNationality' => $breakdown['maps']['nationality'],
            'byPosition'    => $breakdown['maps']['position'],
            'byAge'         => $breakdown['maps']['age'],
            'byAbility'     => $breakdown['maps']['ability'],
        ];
    }

    /**
     * Full pool breakdown for the admin dashboard.
     *
     * One scalar-column pass over the pool, bucketed in PHP: age is a computed
     * property ({@see Player::getAge()}) so it cannot be grouped in DQL, and
     * doing the remaining facets in the same pass keeps this to a single query
     * instead of six.
     *
     * @return array{
     *     total: int,
     *     maps: array<string, array<string,int>>,
     *     facets: array<string, list<array{key: string, count: int}>>,
     *     nested: array{dimension: string, children: list<string>, rows: list<array{key: string, count: int, children: array<string, list<array{key: string, count: int}>>}>}
     * }
     */
    public function getPoolBreakdown(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select(
                'p.nationality AS nationality',
                'p.position AS position',
                'p.dateOfBirth AS dob',
                'p.currentAbility AS ability',
                'p.potential AS potential',
                'p.recruitmentSource AS source',
                'IDENTITY(p.agent) AS agentId'
            )
            ->getQuery()
            ->getArrayResult();

        $now = new \DateTimeImmutable();

        $nationality = [];
        $position    = [];
        $source      = [];
        $age         = StatBuckets::seed(StatBuckets::AGE_LABELS);
        $ability     = StatBuckets::seed(StatBuckets::ABILITY_LABELS);
        $potential   = StatBuckets::seed(StatBuckets::ABILITY_LABELS);
        $agentStatus = ['Represented' => 0, 'Unagented' => 0];

        // nationality => {position|ability|age} => count
        $nested = [];

        foreach ($rows as $row) {
            $nat = (string) ($row['nationality'] ?? StatBuckets::UNKNOWN);
            $pos = $row['position'] instanceof PlayerPosition
                ? $row['position']->value
                : (string) $row['position'];
            $src = $row['source'] instanceof \BackedEnum
                ? (string) $row['source']->value
                : (string) $row['source'];

            $ageBucket     = StatBuckets::age($row['dob'] instanceof \DateTimeInterface ? $row['dob'] : null, $now);
            $abilityBucket = StatBuckets::ability((int) $row['ability']);
            $potBucket     = StatBuckets::ability((int) $row['potential']);

            $nationality[$nat] = ($nationality[$nat] ?? 0) + 1;
            $position[$pos]    = ($position[$pos] ?? 0) + 1;
            $source[$src]      = ($source[$src] ?? 0) + 1;
            $age[$ageBucket]++;
            $ability[$abilityBucket]++;
            $potential[$potBucket]++;
            $agentStatus[$row['agentId'] === null ? 'Unagented' : 'Represented']++;

            $nested[$nat]['position'][$pos]        = ($nested[$nat]['position'][$pos] ?? 0) + 1;
            $nested[$nat]['ability'][$abilityBucket] = ($nested[$nat]['ability'][$abilityBucket] ?? 0) + 1;
            $nested[$nat]['age'][$ageBucket]         = ($nested[$nat]['age'][$ageBucket] ?? 0) + 1;
        }

        arsort($nationality);
        arsort($position);
        arsort($source);

        return [
            'total'  => count($rows),
            'maps'   => compact('nationality', 'position', 'age', 'ability'),
            'facets' => [
                'nationality' => StatBuckets::facet($nationality),
                'position'    => StatBuckets::facet($position),
                'ability'     => StatBuckets::facet($ability),
                'age'         => StatBuckets::facet($age),
                'potential'   => StatBuckets::facet($potential),
                'source'      => StatBuckets::facet($source),
                'agent'       => StatBuckets::facet($agentStatus),
            ],
            'nested' => StatBuckets::nested('nationality', $nationality, $nested, ['position', 'ability', 'age']),
        ];
    }

}
