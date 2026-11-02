<?php

namespace App\Repository;

use App\Entity\Club;
use App\Entity\SeasonRecord;
use App\Enum\StatsPeriod;
use App\Service\PeriodResolver;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeasonRecord>
 */
class SeasonRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeasonRecord::class);
    }

    /** @return SeasonRecord[] ordered by season ASC */
    public function findByClub(Club $club): array
    {
        return $this->findBy(['club' => $club], ['season' => 'ASC']);
    }

    /**
     * The one record for this club+season, or null if the season has not been concluded yet.
     *
     * This is the idempotency check behind POST /api/league/conclude-season, and the pair is
     * unique in the database (`uq_season_record_club_season`), so there is never more than one.
     */
    public function findOneByClubAndSeason(Club $club, int $season): ?SeasonRecord
    {
        return $this->findOneBy(['club' => $club, 'season' => $season]);
    }

    /** Count of concluded seasons for a club — one row per concluded season, deduplicated by club+season. */
    public function countByClub(Club $club): int
    {
        return (int) $this->createQueryBuilder('sr')
            ->select('COUNT(sr.id)')
            ->where('sr.club = :club')
            ->setParameter('club', $club->getId())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return array<array{clubId: string, clubName: string, value: int|string}> */
    public function getMostSeasonsByClub(StatsPeriod $period, int $limit, PeriodResolver $resolver): array
    {
        $qb = $this->createQueryBuilder('sr')
            ->select('c.id as clubId, c.name as clubName, COUNT(sr.id) as value')
            ->innerJoin('sr.club', 'c')
            ->groupBy('c.id')
            ->orderBy('value', 'DESC')
            ->setMaxResults($limit);

        $resolver->applyPeriodFilter($qb, $period, 'sr', 'createdAt', 'c');

        return $qb->getQuery()->getArrayResult();
    }

    /** @return array<array{clubId: string, clubName: string, value: int|string}> */
    public function getMostTrophiesByClub(StatsPeriod $period, int $limit, PeriodResolver $resolver): array
    {
        $qb = $this->createQueryBuilder('sr')
            ->select('c.id as clubId, c.name as clubName, COUNT(sr.id) as value')
            ->innerJoin('sr.club', 'c')
            ->where('sr.finalPosition = :finalPosition')
            ->setParameter('finalPosition', 1)
            ->groupBy('c.id')
            ->orderBy('value', 'DESC')
            ->setMaxResults($limit);

        $resolver->applyPeriodFilter($qb, $period, 'sr', 'createdAt', 'c');

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Title counts per club, grouped by the tier the title was won in.
     * Backs the derived `hall_of_fame` leaderboard — a title is a SeasonRecord
     * with finalPosition = 1, and each tier is worth a different number of points
     * (GameConfig::$leagueWinPoints). The weighting is applied in PHP because the
     * weights live in a json column.
     *
     * @return array<array{clubId: string, tier: int, titles: int}>
     */
    public function countTitlesByClubAndTier(?Club $club = null): array
    {
        $qb = $this->createQueryBuilder('sr')
            ->select('IDENTITY(sr.club) AS clubId', 'l.tier AS tier', 'COUNT(sr.id) AS titles')
            ->innerJoin('sr.league', 'l')
            ->where('sr.finalPosition = 1')
            ->groupBy('sr.club')
            ->addGroupBy('l.tier');

        if ($club !== null) {
            $qb->andWhere('sr.club = :club')->setParameter('club', $club);
        }

        return array_map(
            static fn (array $row): array => [
                'clubId' => (string) $row['clubId'],
                'tier'   => (int) $row['tier'],
                'titles' => (int) $row['titles'],
            ],
            $qb->getQuery()->getArrayResult(),
        );
    }

    /**
     * Recent promotion/relegation/title events, with the real club name attached.
     * Club names come from a curated, server-generated set of options (see
     * /api/club/name-options) rather than free text, so naming a specific club here
     * carries no moderation risk — same reasoning as
     * SyncRecordRepository::findTopAttendanceSince(). Backs the landing page's
     * "Chairman's Terminal" real-events feed (see LiveTelemetryService). (Previously
     * anonymised to tier + outcome only — reversed by product decision.)
     *
     * homeKitConfig/awayKitConfig/badgeConfig are the club's own owner-customized
     * kit+badge identity — see Club entity / docs/api/club-kit-identity.md.
     *
     * @return array<array{tier: int, promoted: bool, relegated: bool, finalPosition: int, createdAt: \DateTimeImmutable, clubName: string, homeKitConfig: ?array, awayKitConfig: ?array, badgeConfig: ?array}>
     */
    public function findRecentPyramidEvents(\DateTimeImmutable $since, int $limit): array
    {
        return $this->createQueryBuilder('sr')
            ->select('l.tier AS tier', 'sr.promoted AS promoted', 'sr.relegated AS relegated', 'sr.finalPosition AS finalPosition', 'sr.createdAt AS createdAt', 'c.id AS clubId', 'c.name AS clubName', 'c.homeKitConfig AS homeKitConfig', 'c.awayKitConfig AS awayKitConfig', 'c.badgeConfig AS badgeConfig')
            ->innerJoin('sr.league', 'l')
            ->innerJoin('sr.club', 'c')
            ->where('sr.createdAt >= :since')
            ->andWhere('sr.promoted = true OR sr.relegated = true OR sr.finalPosition = 1')
            ->setParameter('since', $since)
            ->orderBy('sr.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * How many clubs went up or down across the whole pyramid since $since — the
     * "Table Turnover" figure on the landing page's telemetry HUD.
     *
     * Counts SeasonRecord rows, not live standings: the backend holds no in-progress
     * league table (the device simulates the season and only reports outcomes), so a
     * club's promotion/relegation is only knowable here once its season concluded and
     * LeagueService::concludeSeason() wrote the row. That makes this "promotions and
     * drops that have happened", which is what the HUD's sub-line says.
     *
     * Deliberately excludes finalPosition = 1 — a title win is neither a promotion nor
     * a drop, and findRecentPyramidEvents() includes it only because the FEED narrates
     * titles too.
     */
    public function countPromotionsAndRelegationsSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('sr')
            ->select('COUNT(sr.id)')
            ->where('sr.createdAt >= :since')
            ->andWhere('sr.promoted = true OR sr.relegated = true')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The same window and the same rows as countPromotionsAndRelegationsSince(), split by
     * direction, for the HUD tile that now reports promotions and relegations as two
     * figures rather than one total.
     *
     * One query with two conditional aggregates rather than two COUNT queries: the split
     * must add up to the total the same refresh stores in `tableTurnover`, and two separate
     * queries against a table being written by concludeSeason() can straddle a commit and
     * disagree by a row.
     *
     * A row can be BOTH promoted and relegated in principle (nothing in the schema forbids
     * it), so the two counts can sum to more than the row count. countPromotions-
     * AndRelegationsSince() counts rows. Treated as the lesser evil: a tile that says
     * "1 up, 1 down" about one such row is readable, where silently picking one direction
     * would lose a real movement.
     *
     * @return array{promotions: int, relegations: int}
     */
    public function countTurnoverByDirectionSince(\DateTimeImmutable $since): array
    {
        $row = $this->createQueryBuilder('sr')
            ->select(
                'SUM(CASE WHEN sr.promoted = true THEN 1 ELSE 0 END) AS promotions',
                'SUM(CASE WHEN sr.relegated = true THEN 1 ELSE 0 END) AS relegations',
            )
            ->where('sr.createdAt >= :since')
            ->andWhere('sr.promoted = true OR sr.relegated = true')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleResult();

        return [
            'promotions'  => (int) ($row['promotions'] ?? 0),
            'relegations' => (int) ($row['relegations'] ?? 0),
        ];
    }
}
