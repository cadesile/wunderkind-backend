<?php

namespace App\Repository;

use App\Entity\MatchResult;
use App\Enum\StatsPeriod;
use App\Service\PeriodResolver;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MatchResult>
 */
class MatchResultRepository extends ServiceEntityRepository
{
    /** Minimum matches played in the window before a club can top the FORTRESS_DEFENCE board — guards against a single lucky clean sheet outranking a genuinely solid defensive record. */
    private const MIN_MATCHES_FOR_FORTRESS = 3;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MatchResult::class);
    }

    /**
     * Most match wins per club within the period, tiebroken on goal difference.
     * Filtered on `createdAt` (always-set, server-side) — not `playedAt`, which is
     * nullable/client-supplied and unindexed.
     *
     * @return array<array{clubId: string, clubName: string, value: int|string, secondaryValue: int|string}>
     */
    public function getMostWinsByClub(StatsPeriod $period, int $limit, PeriodResolver $resolver): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select(
                'c.id as clubId',
                'c.name as clubName',
                'SUM(CASE WHEN m.goalsFor > m.goalsAgainst THEN 1 ELSE 0 END) as value',
                'SUM(m.goalsFor - m.goalsAgainst) as secondaryValue',
            )
            ->innerJoin('m.club', 'c')
            ->groupBy('c.id')
            ->orderBy('value', 'DESC')
            ->addOrderBy('secondaryValue', 'DESC')
            ->setMaxResults($limit);

        $resolver->applyPeriodFilter($qb, $period, 'm', 'createdAt', 'c');

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Each club's single biggest match-victory margin within the period. Excludes
     * matches that weren't wins — a "rout" without a win is meaningless.
     *
     * @return array<array{clubId: string, clubName: string, value: int|string}>
     */
    public function getBiggestRoutByClub(StatsPeriod $period, int $limit, PeriodResolver $resolver): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('c.id as clubId, c.name as clubName, MAX(m.goalsFor - m.goalsAgainst) as value')
            ->innerJoin('m.club', 'c')
            ->where('m.goalsFor > m.goalsAgainst')
            ->groupBy('c.id')
            ->orderBy('value', 'DESC')
            ->setMaxResults($limit);

        $resolver->applyPeriodFilter($qb, $period, 'm', 'createdAt', 'c');

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Best defensive record (lowest average goals conceded) per club within the
     * period. Ranked ascending — lower is better. Requires at least
     * MIN_MATCHES_FOR_FORTRESS matches in the window so a single clean sheet can't
     * top the board.
     *
     * @return array<array{clubId: string, clubName: string, value: int|string}>
     */
    public function getBestDefenceByClub(StatsPeriod $period, int $limit, PeriodResolver $resolver): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('c.id as clubId, c.name as clubName, AVG(m.goalsAgainst) as value')
            ->innerJoin('m.club', 'c')
            ->groupBy('c.id')
            ->having('COUNT(m.id) >= :minMatches')
            ->setParameter('minMatches', self::MIN_MATCHES_FOR_FORTRESS)
            ->orderBy('value', 'ASC')
            ->setMaxResults($limit);

        $resolver->applyPeriodFilter($qb, $period, 'm', 'createdAt', 'c');

        return $qb->getQuery()->getArrayResult();
    }
}
