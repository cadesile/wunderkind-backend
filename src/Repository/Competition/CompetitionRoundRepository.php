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

    public function findByCompetitionAndIndex(ActiveCompetition $activeCompetition, int $roundIndex): ?CompetitionRound
    {
        return $this->createQueryBuilder('r')
            ->where('r.activeCompetition = :competition')
            ->andWhere('r.roundIndex = :roundIndex')
            ->setParameter('competition', $activeCompetition->getId())
            ->setParameter('roundIndex', $roundIndex)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** True iff resubmission is currently allowed — a round exists that has not been drawn yet. */
    public function hasUpcomingRound(ActiveCompetition $activeCompetition): bool
    {
        $count = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.activeCompetition = :competition')
            ->andWhere('r.status = :status')
            ->setParameter('competition', $activeCompetition->getId())
            ->setParameter('status', CompetitionRoundStatus::DRAW_PENDING)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $count) > 0;
    }

    /**
     * The round currently in progress or next up (not yet started) — null once every round is
     * RESULTS_PUBLISHED/CANCELLED, or before the bracket has been drawn (a REGISTERING instance
     * has no rounds yet).
     */
    public function findCurrentRound(ActiveCompetition $activeCompetition): ?CompetitionRound
    {
        return $this->createQueryBuilder('r')
            ->where('r.activeCompetition = :competition')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('competition', $activeCompetition->getId())
            ->setParameter('statuses', [
                CompetitionRoundStatus::DRAW_PENDING,
                CompetitionRoundStatus::DRAWN,
            ])
            ->orderBy('r.roundIndex', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Rounds due to be drawn — the query CompetitionDrawService's cron pass runs every tick.
     *
     * @return list<CompetitionRound>
     */
    public function findDueForDraw(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->andWhere('r.scheduledAt <= :now')
            ->setParameter('status', CompetitionRoundStatus::DRAW_PENDING)
            ->setParameter('now', $now)
            ->orderBy('r.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Rounds due to have their results published — the query CompetitionResultsService's
     * cron pass runs every tick.
     *
     * @return list<CompetitionRound>
     */
    public function findDueForResults(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->andWhere('r.matchesResolveAt <= :now')
            ->setParameter('status', CompetitionRoundStatus::DRAWN)
            ->setParameter('now', $now)
            ->orderBy('r.matchesResolveAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Drawn rounds whose results resolve within the reminder lead time and haven't been
     * reminded about yet — see CompetitionRoundReminderService. `matchesResolveAt > $now`
     * excludes rounds already due (or overdue): those get resolved outright by the results
     * service, so a "results incoming" push for one would be stale by the time it's delivered.
     *
     * @return list<CompetitionRound>
     */
    public function findDueForReminder(\DateTimeImmutable $now, \DateTimeImmutable $windowEnd): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->andWhere('r.reminderSentAt IS NULL')
            ->andWhere('r.matchesResolveAt > :now')
            ->andWhere('r.matchesResolveAt <= :windowEnd')
            ->setParameter('status', CompetitionRoundStatus::DRAWN)
            ->setParameter('now', $now)
            ->setParameter('windowEnd', $windowEnd)
            ->orderBy('r.matchesResolveAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
