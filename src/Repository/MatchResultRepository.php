<?php

namespace App\Repository;

use App\Entity\Club;
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
     * The club's last $limit results, newest first — filtered on `createdAt`
     * (always-set, server-side), same reasoning as the stat queries below.
     *
     * (season, week) are the real tiebreakers and are NOT decoration:
     * SyncService::processMatchResults() builds every result from one payload
     * in a single loop, each with its own new \DateTimeImmutable(), and
     * `datetime_immutable` persists at second precision — so a whole batch
     * routinely shares one createdAt (the production data has batches of 400+
     * rows on a single second). Ordering on createdAt alone therefore returns
     * an arbitrary slice of that batch, and ->setMaxResults() can cut out the
     * genuinely-latest matches. createdAt stays the primary key of the sort so
     * cross-batch recency still wins; (season, week) only breaks ties inside a
     * batch.
     *
     * @return MatchResult[]
     */
    public function findRecentByClub(Club $club, int $limit): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.club = :club')
            ->setParameter('club', $club)
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.season', 'DESC')
            ->addOrderBy('m.week', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The most recent results for several clubs at once, bucketed by club id and
     * capped at $perClub each — the batch form of findRecentByClub(), for the
     * boardroom overlay's per-club dossier (LiveTelemetryService::buildClubDossiers()).
     *
     * Over-fetches ($perClub * club count) and trims in PHP rather than issuing one
     * query per club or a window-function CTE: the telemetry refresh runs against at
     * most TOTAL_FEED_LIMIT distinct clubs, so the over-fetch is bounded and small.
     *
     * Keyed and ordered exactly like findRecentByClub() so both can feed the same
     * ClubSpotlightService::buildRecentFixtures()-shaped serializer.
     *
     * @param  string[] $clubIds
     * @return array<string, MatchResult[]> club id => newest-first results
     */
    public function findRecentByClubIds(array $clubIds, int $perClub): array
    {
        if ($clubIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('m')
            ->where('m.club IN (:clubIds)')
            ->setParameter('clubIds', $clubIds)
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.season', 'DESC')
            ->addOrderBy('m.week', 'DESC')
            ->setMaxResults($perClub * count($clubIds))
            ->getQuery()
            ->getResult();

        $byClub = [];
        foreach ($rows as $row) {
            $key = (string) $row->getClub()->getId();
            if (count($byClub[$key] ?? []) >= $perClub) {
                continue;
            }
            $byClub[$key][] = $row;
        }

        return $byClub;
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
