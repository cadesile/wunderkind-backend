<?php

namespace App\Repository\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Club;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\EntrantSquadState;
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

    /**
     * Entrants still holding a slot whose squad row has not landed (pending or failed).
     *
     * Lives here rather than on either service because two of them need the same answer for
     * two different decisions, and the two must not drift: a competition may not be LOCKED
     * while this is non-zero (CompetitionRegistrationService::lockIfComplete) and may not be
     * DRAWN while it is (CompetitionDrawService::drawRoundIfClaimable).
     *
     * Withdrawn entrants are excluded: a withdrawn club's unstored squad blocks nothing, and
     * counting it would make withdrawal useless as the recovery it is meant to be.
     */
    public function countUnstoredForCompetition(ActiveCompetition $activeCompetition): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            <<<'SQL'
            SELECT COUNT(*)
              FROM competition_entrant
             WHERE active_competition_id = :competitionId
               AND status <> :withdrawn
               AND squad_state <> :stored
            SQL,
            [
                'competitionId' => $activeCompetition->getId()->toRfc4122(),
                'withdrawn'     => CompetitionEntrantStatus::WITHDRAWN->value,
                'stored'        => EntrantSquadState::STORED->value,
            ],
        );
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
     * Every entrant row, withdrawn included.
     *
     * Paired with countForCompetition() (which excludes withdrawn) in the response
     * fingerprint, so a withdrawal moves one component without being cancelled out by
     * another club's resubmission moving a different one. Summing them into a single number
     * is exactly the bug the fingerprint replaced.
     */
    public function countAllForCompetition(ActiveCompetition $activeCompetition): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.activeCompetition = :competition')
            ->setParameter('competition', $activeCompetition->getId())
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
