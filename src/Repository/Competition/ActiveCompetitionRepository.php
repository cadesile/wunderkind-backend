<?php

namespace App\Repository\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionTemplate;
use App\Enum\Competition\ActiveCompetitionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActiveCompetition>
 */
class ActiveCompetitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActiveCompetition::class);
    }

    public function findOpenForTemplate(CompetitionTemplate $template): ?ActiveCompetition
    {
        return $this->createQueryBuilder('a')
            ->where('a.template = :template')
            ->andWhere('a.status = :status')
            ->setParameter('template', $template->getId())
            ->setParameter('status', ActiveCompetitionStatus::REGISTERING)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<ActiveCompetition> */
    public function findByStatus(ActiveCompetitionStatus $status): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->setParameter('status', $status)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Instances that filled to capacity and locked, but haven't finished yet — SCHEDULED
     * (locked, not started) or RUNNING. A REGISTERING instance can never be "full": filling
     * the last slot flips it straight to SCHEDULED in the same transaction (see
     * CompetitionLockService), so there's no separate capacity check to make here.
     * CANCELLED is deliberately excluded — not "active" in any useful sense.
     *
     * @return list<ActiveCompetition>
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status IN (:statuses)')
            ->setParameter('statuses', [ActiveCompetitionStatus::SCHEDULED, ActiveCompetitionStatus::RUNNING])
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<ActiveCompetition> Most recently completed instances, for "historical winners." */
    public function findRecentlyCompleted(int $limit): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->setParameter('status', ActiveCompetitionStatus::COMPLETED)
            ->orderBy('a.completedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Candidates for CompetitionAutoFillService — still REGISTERING, opted into auto-fill,
     * with at least one entrant already registered (the delay is measured from that
     * entrant's registeredAt, checked by the caller since it varies per-template and isn't
     * worth expressing as SQL interval arithmetic here — this result set is always small).
     *
     * @return list<ActiveCompetition>
     */
    public function findEligibleForAutoFill(): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.template', 't')
            ->where('a.status = :status')
            ->andWhere('t.autoFillSpoofEntrants = true')
            ->andWhere('EXISTS (SELECT 1 FROM App\Entity\Competition\CompetitionEntrant e WHERE e.activeCompetition = a)')
            ->setParameter('status', ActiveCompetitionStatus::REGISTERING)
            ->getQuery()
            ->getResult();
    }
}
