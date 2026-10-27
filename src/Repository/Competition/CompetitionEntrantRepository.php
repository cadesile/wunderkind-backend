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

    /**
     * Sum of every entrant's snapshotVersion for this competition.
     *
     * Feeds the instance state version, which previously counted only entrants and round
     * statuses. That made a resubmission invisible to it: swapping a squad changes neither
     * the entrant count nor any round status, so the version held still while the response
     * genuinely changed. The ETag only escaped the consequences by also folding in the
     * *viewing* club's own snapshot version — meaning another club's resubmission already
     * failed to invalidate your ETag, and would have served a stale shared cache document.
     *
     * Withdrawn rows are included on purpose, unlike countForCompetition(): a withdrawal is
     * itself a change the response must reflect, and excluding them would let a withdraw and
     * a resubmission cancel out to the same total.
     */
    public function sumSnapshotVersions(ActiveCompetition $activeCompetition): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COALESCE(SUM(e.snapshotVersion), 0)')
            ->where('e.activeCompetition = :competition')
            ->setParameter('competition', $activeCompetition->getId())
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
