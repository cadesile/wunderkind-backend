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

    /**
     * The final fixture of many competitions in ONE query, keyed by competition id — the
     * batched form of findFinalFixtureForCompetition(), for the Hall of Fame history page,
     * which wants the final of up to 50 instances and nothing else from any of them.
     *
     * The final's round index is log2(capacity), so it differs per competition. The pairs
     * therefore go in as an OR of (competition, roundIndex) rather than two IN lists: an
     * IN/IN cross product would also match a 4-club cup's round 3, which belongs to a
     * 8-club cup's bracket and not to this one's.
     *
     * Home, away and winner are fetch-joined because championPair() dereferences all three.
     * Left over as lazy proxies that is three extra round trips per row, which is the whole
     * saving this method exists for.
     *
     * @param  list<ActiveCompetition> $competitions
     * @return array<string, CompetitionFixture> competition id (RFC 4122) => its final fixture
     */
    public function findFinalFixturesForCompetitions(array $competitions): array
    {
        if ($competitions === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('f')
            ->innerJoin('f.round', 'r')->addSelect('r')
            ->leftJoin('f.homeEntrant', 'he')->addSelect('he')
            ->leftJoin('f.awayEntrant', 'ae')->addSelect('ae')
            ->leftJoin('f.winnerEntrant', 'we')->addSelect('we')
            // The final round holds one fixture, at slot 0 — the same assumption
            // CupResultsBoardService::finalOf() makes, and equivalent to the single-row
            // method's ORDER BY slotIndex ASC / setMaxResults(1).
            ->andWhere('f.slotIndex = 0');

        // Reindexed so the parameter names are always :c0..:cN. The callers pass lists, but a
        // string or sparse key arriving here would produce an invalid DQL parameter name.
        $clauses = [];
        foreach (array_values($competitions) as $i => $competition) {
            $clauses[] = sprintf('(r.activeCompetition = :c%d AND r.roundIndex = :ri%d)', $i, $i);
            $qb->setParameter('c' . $i, $competition->getId())
                ->setParameter('ri' . $i, (int) round(log($competition->getEntrantCapacity(), 2)));
        }
        $qb->andWhere(implode(' OR ', $clauses));

        $byCompetition = [];
        foreach ($qb->getQuery()->getResult() as $fixture) {
            $byCompetition[$fixture->getRound()->getActiveCompetition()->getId()->toRfc4122()] = $fixture;
        }

        return $byCompetition;
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
