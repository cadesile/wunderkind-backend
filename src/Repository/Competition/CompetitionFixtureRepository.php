<?php

namespace App\Repository\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionRound;
use App\Enum\Competition\CompetitionFixtureStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitionFixture>
 */
class CompetitionFixtureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitionFixture::class);
    }

    /** @return list<CompetitionFixture> */
    public function findByRoundOrderedBySlot(CompetitionRound $round): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.round = :round')
            ->setParameter('round', $round->getId())
            ->orderBy('f.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findNextPendingFixtureForEntrant(CompetitionEntrant $entrant): ?CompetitionFixture
    {
        return $this->createQueryBuilder('f')
            ->innerJoin('f.round', 'r')
            ->where('r.activeCompetition = :competition')
            ->andWhere('f.status = :status')
            ->andWhere('(f.homeEntrant = :entrant OR f.awayEntrant = :entrant)')
            ->setParameter('competition', $entrant->getActiveCompetition()->getId())
            ->setParameter('status', CompetitionFixtureStatus::PENDING)
            ->setParameter('entrant', $entrant->getId())
            ->orderBy('r.roundIndex', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findFinalFixtureForCompetition(ActiveCompetition $competition): ?CompetitionFixture
    {
        $totalRounds = (int) round(log($competition->getEntrantCapacity(), 2));

        return $this->createQueryBuilder('f')
            ->innerJoin('f.round', 'r')
            ->where('r.activeCompetition = :competition')
            ->andWhere('r.roundIndex = :finalIndex')
            ->setParameter('competition', $competition->getId())
            ->setParameter('finalIndex', $totalRounds)
            ->orderBy('f.slotIndex', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<CompetitionFixture> */
    public function findCompletedForClub(\App\Entity\Club $club, int $limit = 30): array
    {
        return $this->createQueryBuilder('f')
            ->innerJoin('f.round', 'r')
            ->leftJoin('f.homeEntrant', 'he')
            ->leftJoin('f.awayEntrant', 'ae')
            ->where('f.status = :status')
            ->andWhere('(he.club = :club OR ae.club = :club)')
            ->setParameter('status', CompetitionFixtureStatus::COMPLETE)
            ->setParameter('club', $club->getId())
            ->orderBy('f.processedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<CompetitionFixture> */
    public function findCompletedBetweenClubs(string $clubAId, string $clubBId): array
    {
        return $this->createQueryBuilder('f')
            ->innerJoin('f.round', 'r')
            ->innerJoin('f.homeEntrant', 'he')
            ->innerJoin('f.awayEntrant', 'ae')
            ->where('f.status = :status')
            ->andWhere('((he.club = :clubA AND ae.club = :clubB) OR (he.club = :clubB AND ae.club = :clubA))')
            ->setParameter('status', CompetitionFixtureStatus::COMPLETE)
            ->setParameter('clubA', $clubAId)
            ->setParameter('clubB', $clubBId)
            ->orderBy('f.processedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
