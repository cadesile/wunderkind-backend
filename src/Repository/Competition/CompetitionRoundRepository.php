<?php

namespace App\Repository\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionRound;
use App\Enum\Competition\CompetitionRoundStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitionRound>
 */
class CompetitionRoundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitionRound::class);
    }

    /** @return list<CompetitionRound> */
    public function findByCompetitionOrderedByIndex(ActiveCompetition $activeCompetition): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.activeCompetition = :competition')
            ->setParameter('competition', $activeCompetition->getId())
            ->orderBy('r.roundIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** True iff resubmission is currently allowed — a round exists that has not started running yet. */
    public function hasUpcomingRound(ActiveCompetition $activeCompetition): bool
    {
        $count = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.activeCompetition = :competition')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('competition', $activeCompetition->getId())
            ->setParameter('statuses', [CompetitionRoundStatus::PENDING, CompetitionRoundStatus::SCHEDULED])
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $count) > 0;
    }

    /**
     * Rounds due for execution — the query the round processor cron runs every tick.
     *
     * @return list<CompetitionRound>
     */
    public function findDueRounds(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status IN (:statuses)')
            ->andWhere('r.scheduledAt <= :now')
            ->setParameter('statuses', [CompetitionRoundStatus::PENDING, CompetitionRoundStatus::SCHEDULED])
            ->setParameter('now', $now)
            ->orderBy('r.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
