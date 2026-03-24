<?php

namespace App\Repository;

use App\Entity\Academy;
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

    /** @return Player[] Unassigned YOUTH_INTAKE players (open market pool) */
    public function findInPool(int $limit = 100, ?string $nationality = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.academy IS NULL')
            ->andWhere('p.recruitmentSource = :source')
            ->setParameter('source', RecruitmentSource::YOUTH_INTAKE)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($nationality !== null) {
            $qb->andWhere('p.nationality = :nationality')
               ->setParameter('nationality', $nationality);
        }

        return $qb->getQuery()->getResult();
    }

    public function countInPool(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.academy IS NULL')
            ->andWhere('p.recruitmentSource = :source')
            ->setParameter('source', RecruitmentSource::YOUTH_INTAKE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Player[] Unassigned SCOUTING_NETWORK players (scout prospect pool) */
    public function findProspects(int $limit = 150): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.academy IS NULL')
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
            ->where('p.academy IS NULL')
            ->andWhere('p.recruitmentSource = :source')
            ->setParameter('source', RecruitmentSource::SCOUTING_NETWORK)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Player[] */
    public function findByAcademy(Academy $academy): array
    {
        return $this->findBy(['academy' => $academy]);
    }

    /**
     * Returns players excluding all transferred statuses.
     *
     * @return Player[]
     */
    public function findActiveByAcademy(Academy $academy): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.academy = :academy')
            ->andWhere('p.status NOT IN (:excluded)')
            ->setParameter('academy', $academy)
            ->setParameter('excluded', [
                PlayerStatus::TRANSFERRED->value,
                PlayerStatus::TRANSFERRED_VIA_AGENT->value,
            ])
            ->orderBy('p.lastName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
