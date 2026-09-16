<?php

namespace App\Repository\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Club;
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
            ->setParameter('competition', $activeCompetition->getId())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<CompetitionEntrant> Registration order (used to seed the initial bracket). */
    public function findByCompetitionOrderedByRegistration(ActiveCompetition $activeCompetition): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.activeCompetition = :competition')
            ->setParameter('competition', $activeCompetition->getId())
            ->orderBy('e.registeredAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
