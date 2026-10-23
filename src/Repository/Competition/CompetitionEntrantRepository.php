<?php

namespace App\Repository\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Club;
use App\Enum\Competition\CompetitionEntrantStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitionEntrant>
 */
class CompetitionEntrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitionEntrant::class);
    }

    public function findByCompetitionAndClub(ActiveCompetition $activeCompetition, Club $club): ?CompetitionEntrant
    {
        return $this->createQueryBuilder('e')
            ->where('e.activeCompetition = :competition')
            ->andWhere('e.club = :club')
            ->setParameter('competition', $activeCompetition->getId())
            ->setParameter('club', $club->getId())
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countForCompetition(ActiveCompetition $activeCompetition): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.activeCompetition = :competition')
            ->andWhere('e.status != :withdrawn')
            ->setParameter('competition', $activeCompetition->getId())
            ->setParameter('withdrawn', CompetitionEntrantStatus::WITHDRAWN)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<CompetitionEntrant> Registration order (used to seed the initial bracket), excluding withdrawn rows. */
    public function findByCompetitionOrderedByRegistration(ActiveCompetition $activeCompetition): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.activeCompetition = :competition')
            ->andWhere('e.status != :withdrawn')
            ->setParameter('competition', $activeCompetition->getId())
            ->setParameter('withdrawn', CompetitionEntrantStatus::WITHDRAWN)
            ->orderBy('e.registeredAt', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<CompetitionEntrant> All entrants for a club across competitions (excluding withdrawn by default). */
    public function findByClub(Club $club, bool $includeWithdrawn = false): array
    {
        $qb = $this->createQueryBuilder('e')
            ->innerJoin('e.activeCompetition', 'c')
            ->addSelect('c')
            ->where('e.club = :club')
            ->setParameter('club', $club->getId())
            ->orderBy('e.registeredAt', 'DESC');

        if (!$includeWithdrawn) {
            $qb->andWhere('e.status != :withdrawn')
                ->setParameter('withdrawn', CompetitionEntrantStatus::WITHDRAWN);
        }

        return $qb->getQuery()->getResult();
    }
}
