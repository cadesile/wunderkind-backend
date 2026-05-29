<?php

namespace App\Repository;

use App\Entity\Club;
use App\Entity\Guardian;
use App\Entity\Player;
use App\Enum\PlayerPosition;
use App\Enum\PlayerStatus;
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
            ->where('p.club IS NULL')
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
            ->where('p.club IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countInPoolByNationality(string $nationality): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.club IS NULL')
            ->andWhere('p.nationality = :nat')
            ->setParameter('nat', $nationality)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Player[] */
    public function findByClub(Club $club): array
    {
        return $this->findBy(['club' => $club]);
    }

    /**
     * Returns players excluding all transferred statuses.
     *
     * @return Player[]
     */
    public function findActiveByClub(Club $club): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.club = :club')
            ->andWhere('p.status NOT IN (:excluded)')
            ->setParameter('club', $club)
            ->setParameter('excluded', [
                PlayerStatus::TRANSFERRED->value,
                PlayerStatus::TRANSFERRED_VIA_AGENT->value,
            ])
            ->orderBy('p.lastName', 'ASC')
            ->getQuery()
            ->getResult();
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
            ->where('p.club IS NULL')
            ->andWhere('p.currentAbility BETWEEN :min AND :max')
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
            ->where('p.club IS NULL')
            ->andWhere('p.currentAbility BETWEEN :min AND :max')
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
     * @return Player[]
     */
    public function findForWorldInitByPositionAndNationality(int $abilityMin, int $abilityMax, PlayerPosition $position, string $nationality, int $limit): array
    {
        if ($limit <= 0) return [];

        return $this->createQueryBuilder('p')
            ->addSelect('RAND() AS HIDDEN rand_order')
            ->where('p.club IS NULL')
            ->andWhere('p.currentAbility BETWEEN :min AND :max')
            ->andWhere('p.position = :position')
            ->andWhere('p.nationality = :nationality')
            ->setParameter('min', $abilityMin)
            ->setParameter('max', $abilityMax)
            ->setParameter('position', $position)
            ->setParameter('nationality', $nationality)
            ->setMaxResults($limit)
            ->orderBy('rand_order')
            ->getQuery()
            ->getResult();
    }

    /**
     * Random pool draw filtered by ability range, position, excluding a nationality (foreign slot per position).
     * Pass '__none__' as $excludeNationality to draw from any nationality (backfill use case).
     * @return Player[]
     */
    public function findForeignForWorldInitByPosition(int $abilityMin, int $abilityMax, string $excludeNationality, PlayerPosition $position, int $limit): array
    {
        if ($limit <= 0) return [];

        return $this->createQueryBuilder('p')
            ->addSelect('RAND() AS HIDDEN rand_order')
            ->where('p.club IS NULL')
            ->andWhere('p.currentAbility BETWEEN :min AND :max')
            ->andWhere('p.position = :position')
            ->andWhere('p.nationality != :nationality')
            ->setParameter('min', $abilityMin)
            ->setParameter('max', $abilityMax)
            ->setParameter('position', $position)
            ->setParameter('nationality', $excludeNationality)
            ->setMaxResults($limit)
            ->orderBy('rand_order')
            ->getQuery()
            ->getResult();
    }

    /**
     * Random pool draw filtered by ability range, excluding a nationality (for foreign players).
     * @return Player[]
     */
    public function findForeignForWorldInit(int $abilityMin, int $abilityMax, string $excludeNationality, int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('RAND() AS HIDDEN rand_order')
            ->where('p.club IS NULL')
            ->andWhere('p.currentAbility BETWEEN :min AND :max')
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
            ->where('p.club IS NULL')
            ->andWhere('p.currentAbility BETWEEN :abilityMin AND :abilityMax')
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
     * Returns three summary maps for the player admin panel.
     * Age buckets are computed in PHP from dateOfBirth to avoid PostgreSQL-specific DQL.
     *
     * @return array{byNationality: array<string,int>, byPosition: array<string,int>, byAge: array<string,int>}
     */
    public function getAdminSummary(): array
    {
        // ── By nationality ────────────────────────────────────────────────────
        $natRows = $this->createQueryBuilder('p')
            ->select('p.nationality AS nationality, COUNT(p.id) AS cnt')
            ->groupBy('p.nationality')
            ->orderBy('cnt', 'DESC')
            ->getQuery()
            ->getResult();

        $byNationality = [];
        foreach ($natRows as $row) {
            $byNationality[(string) $row['nationality']] = (int) $row['cnt'];
        }

        // ── By position ───────────────────────────────────────────────────────
        $posRows = $this->createQueryBuilder('p')
            ->select('p.position AS position, COUNT(p.id) AS cnt')
            ->groupBy('p.position')
            ->getQuery()
            ->getResult();

        $byPosition = [];
        foreach ($posRows as $row) {
            $pos = $row['position'] instanceof PlayerPosition
                ? $row['position']->value
                : (string) $row['position'];
            $byPosition[$pos] = (int) $row['cnt'];
        }

        $byAge = ['U16' => 0, '16-18' => 0, '19-21' => 0, '22-25' => 0, '26-30' => 0, '30+' => 0];
        $byAbility = ['1-20' => 0, '21-40' => 0, '41-60' => 0, '61-80' => 0, '81-100' => 0];
        $now   = new \DateTimeImmutable();

        $playerData = $this->createQueryBuilder('p')
            ->select('p.dateOfBirth AS dob, p.currentAbility AS ability')
            ->getQuery()
            ->getArrayResult();

        foreach ($playerData as $row) {
            // Age breakdown
            $dob = $row['dob'];
            if ($dob instanceof \DateTimeInterface) {
                $age    = (int) $dob->diff($now)->y;
                $ageBucket = match (true) {
                    $age < 16   => 'U16',
                    $age <= 18  => '16-18',
                    $age <= 21  => '19-21',
                    $age <= 25  => '22-25',
                    $age < 30   => '26-30',
                    default     => '30+',
                };
                $byAge[$ageBucket]++;
            }

            // Ability breakdown
            $a = (int) $row['ability'];
            $abilityBucket = match (true) {
                $a <= 20 => '1-20',
                $a <= 40 => '21-40',
                $a <= 60 => '41-60',
                $a <= 80 => '61-80',
                default  => '81-100',
            };
            $byAbility[$abilityBucket]++;
        }

        return compact('byNationality', 'byPosition', 'byAge', 'byAbility');
    }
}
