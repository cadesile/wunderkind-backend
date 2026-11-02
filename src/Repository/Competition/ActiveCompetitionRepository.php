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
     * Completed instances that actually know WHEN they completed, newest first.
     *
     * Separate from findRecentlyCompleted() because of how Postgres orders nulls: `ORDER BY
     * completed_at DESC` is NULLS **FIRST**, so a row whose status is COMPLETED but whose
     * completedAt is blank ranks ahead of every real date. That row is reachable — the admin
     * form (ActiveCompetitionCrudController) offers `status` and `completedAt` as independent
     * fields, so saving one without the other is a click away — and the landing page's Cup
     * Results board would then present it as the LATEST cup final and date it today.
     *
     * The app's own `recentlyCompleted` list keeps the looser query: there, an undated
     * instance is still a completed instance worth listing, and only its position is odd.
     * Here the position IS the content, so an instance that cannot be dated is excluded
     * rather than ranked.
     *
     * @return list<ActiveCompetition>
     */
    public function findRecentlyCompletedWithKnownCompletion(int $limit): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->andWhere('a.completedAt IS NOT NULL')
            ->setParameter('status', ActiveCompetitionStatus::COMPLETED)
            ->orderBy('a.completedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Cups whose final was settled inside a window, newest first — the telemetry feed's
     * `CUP // WINNER` lines, which report "a cup was won in the last 7 days" rather than
     * "the last few cups ever", the way findRecentlyCompletedWithKnownCompletion() does.
     *
     * Shares that method's IS NOT NULL guard, and for a second reason here: completedAt is
     * the only timestamp that can place a cup inside the window at all, so a row without
     * one cannot be reported as recent.
     *
     * @return list<ActiveCompetition>
     */
    public function findCompletedSince(\DateTimeImmutable $since, int $limit): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->andWhere('a.completedAt IS NOT NULL')
            ->andWhere('a.completedAt >= :since')
            ->setParameter('status', ActiveCompetitionStatus::COMPLETED)
            ->setParameter('since', $since)
            ->orderBy('a.completedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findLatestCompletedForTemplate(CompetitionTemplate $template): ?ActiveCompetition
    {
        return $this->createQueryBuilder('a')
            ->where('a.template = :template')
            ->andWhere('a.status = :status')
            ->setParameter('template', $template->getId())
            ->setParameter('status', ActiveCompetitionStatus::COMPLETED)
            ->orderBy('a.completedAt', 'DESC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Consolidated filterable competition query for GET /api/competitions.
     *
     * @param list<ActiveCompetitionStatus>|null $statuses
     * @param list<int>|null                     $capacities
     * @return list<ActiveCompetition>
     */
    public function findFiltered(
        ?array $statuses = null,
        ?array $capacities = null,
        ?string $templateId = null,
        string $sort = 'startsAt',
        int $offset = 0,
        int $limit = 20,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->innerJoin('a.template', 't')
            ->where('t.isActive = true');

        if ($statuses !== null && $statuses !== []) {
            $qb->andWhere('a.status IN (:statuses)')
                ->setParameter('statuses', $statuses);
        } else {
            $qb->andWhere('a.status != :cancelled')
                ->setParameter('cancelled', ActiveCompetitionStatus::CANCELLED);
        }

        if ($capacities !== null && $capacities !== []) {
            $qb->andWhere('a.entrantCapacity IN (:capacities)')
                ->setParameter('capacities', $capacities);
        }

        if ($templateId !== null && $templateId !== '') {
            $qb->andWhere('t.id = :templateId')
                ->setParameter('templateId', $templateId);
        }

        if ($sort === 'completedAt') {
            $qb->orderBy('a.completedAt', 'DESC')
                ->addOrderBy('a.createdAt', 'DESC');
        } else {
            $qb->orderBy('a.createdAt', 'DESC');
        }

        return $qb->setFirstResult($offset)
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
