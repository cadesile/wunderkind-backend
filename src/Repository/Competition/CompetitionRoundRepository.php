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
     * The round currently in progress or next up (not yet started) — null once every round is
     * COMPLETED/CANCELLED, or before the bracket has been drawn (a REGISTERING instance has no
     * rounds yet).
     */
    public function findCurrentRound(ActiveCompetition $activeCompetition): ?CompetitionRound
    {
        return $this->createQueryBuilder('r')
            ->where('r.activeCompetition = :competition')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('competition', $activeCompetition->getId())
            ->setParameter('statuses', [
                CompetitionRoundStatus::PENDING,
                CompetitionRoundStatus::SCHEDULED,
                CompetitionRoundStatus::RUNNING,
            ])
            ->orderBy('r.roundIndex', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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

    /**
     * Rounds starting within the reminder lead time that haven't been reminded about yet —
     * see CompetitionRoundReminderService. `scheduledAt > $now` excludes rounds already due
     * (or overdue): those get processed outright by the round processor, so a "starting soon"
     * push for one would be stale by the time it's delivered.
     *
     * @return list<CompetitionRound>
     */
    public function findDueForReminder(\DateTimeImmutable $now, \DateTimeImmutable $windowEnd): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status IN (:statuses)')
            ->andWhere('r.reminderSentAt IS NULL')
            ->andWhere('r.scheduledAt > :now')
            ->andWhere('r.scheduledAt <= :windowEnd')
            ->setParameter('statuses', [CompetitionRoundStatus::PENDING, CompetitionRoundStatus::SCHEDULED])
            ->setParameter('now', $now)
            ->setParameter('windowEnd', $windowEnd)
            ->orderBy('r.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
