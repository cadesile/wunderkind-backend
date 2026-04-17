<?php

namespace App\Repository;

use App\Entity\Club;
use App\Entity\Player;
use App\Enum\PlayerStatus;
use App\Enum\RecruitmentSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PlayerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Player::class);
    }

    /**
     * @return Player[] Unassigned YOUTH_INTAKE players (open market pool)
     * @param int|null $abilityMin If provided, only players with currentAbility >= this value
     * @param int|null $abilityMax If provided, only players with currentAbility <= this value
     */
    public function findInPool(int $limit = 100, ?string $nationality = null, ?int $abilityMin = null, ?int $abilityMax = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.club IS NULL')
            ->andWhere('p.recruitmentSource = :source')
            ->setParameter('source', RecruitmentSource::YOUTH_INTAKE)
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
            ->andWhere('p.recruitmentSource = :source')
            ->setParameter('source', RecruitmentSource::YOUTH_INTAKE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countSeniorInPool(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.club IS NULL')
            ->andWhere('p.recruitmentSource = :source')
            ->setParameter('source', RecruitmentSource::SENIOR_INTAKE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Player[] Unassigned SCOUTING_NETWORK players (scout prospect pool) */
    public function findProspects(int $limit = 150): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.club IS NULL')
            ->andWhere('p.recruitmentSource = :source')
            ->setParameter('source', RecruitmentSource::SCOUTING_NETWORK)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countProspects(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.club IS NULL')
            ->andWhere('p.recruitmentSource = :source')
            ->setParameter('source', RecruitmentSource::SCOUTING_NETWORK)
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
     * Bulk-delete players by UUID array. Used after world-init dispatch.
     * @param string[] $ids
     */
    public function deleteByIds(array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $this->createQueryBuilder('p')
            ->delete()
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }
}
