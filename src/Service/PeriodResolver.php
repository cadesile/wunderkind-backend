<?php

namespace App\Service;

use App\Entity\SeasonRecord;
use App\Enum\StatsPeriod;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\ORM\QueryBuilder;

class PeriodResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Applies a period-based date lower-bound filter to $qb, filtering
     * $alias.$dateField. $clubAlias must already be joined to Club in $qb.
     *
     * 'season' has no global boundary — clubs conclude seasons independently
     * via POST /api/league/conclude-season. Its lower bound is per-club: the
     * most recent SeasonRecord.createdAt for that club, or no bound at all
     * if the club has no SeasonRecord yet.
     */
    public function applyPeriodFilter(
        QueryBuilder $qb,
        StatsPeriod $period,
        string $alias,
        string $dateField,
        string $clubAlias,
    ): void {
        $now = new \DateTimeImmutable();

        switch ($period) {
            case StatsPeriod::LAST_6_HOURS:
                $qb->andWhere("{$alias}.{$dateField} >= :periodStart")
                    ->setParameter('periodStart', $now->modify('-6 hours'));
                break;

            case StatsPeriod::LAST_24_HOURS:
                $qb->andWhere("{$alias}.{$dateField} >= :periodStart")
                    ->setParameter('periodStart', $now->modify('-24 hours'));
                break;

            case StatsPeriod::WEEK:
                $qb->andWhere("{$alias}.{$dateField} >= :periodStart")
                    ->setParameter('periodStart', $now->modify('-7 days'));
                break;

            case StatsPeriod::MONTH:
                $qb->andWhere("{$alias}.{$dateField} >= :periodStart")
                    ->setParameter('periodStart', $now->modify('-30 days'));
                break;

            case StatsPeriod::SEASON:
                $this->applySeasonFilter($qb, $alias, $dateField, $clubAlias);
                break;

            case StatsPeriod::ALL:
                break;
        }
    }

    /**
     * Same date-bound logic as applyPeriodFilter(), as a scalar — for raw-SQL call sites
     * that can't use a DQL QueryBuilder (e.g. PlayerCareerStatSnapshotRepository's
     * window-function CTEs). Null for ALL (no bound) and SEASON (per-club bound — callers
     * needing SEASON must resolve it themselves, see applySeasonFilter()'s DQL for the
     * equivalent per-club MAX(SeasonRecord.createdAt) logic).
     */
    public function resolveFixedLowerBound(StatsPeriod $period): ?\DateTimeImmutable
    {
        $now = new \DateTimeImmutable();

        return match ($period) {
            StatsPeriod::LAST_6_HOURS  => $now->modify('-6 hours'),
            StatsPeriod::LAST_24_HOURS => $now->modify('-24 hours'),
            StatsPeriod::WEEK          => $now->modify('-7 days'),
            StatsPeriod::MONTH         => $now->modify('-30 days'),
            StatsPeriod::SEASON, StatsPeriod::ALL => null,
        };
    }

    private function applySeasonFilter(
        QueryBuilder $qb,
        string $alias,
        string $dateField,
        string $clubAlias,
    ): void {
        // DQL's NullComparisonExpression doesn't accept an arbitrary subselect
        // on its left side, so "(subquery) IS NULL" isn't valid DQL. Express
        // "club has no SeasonRecord" via NOT EXISTS instead.
        //
        // Subquery aliases are namespaced ("__period_*") because the outer
        // query this filter is applied to can itself be a SeasonRecord query
        // aliased "sr" (e.g. SeasonRecordRepository::getMostSeasonsByClub) —
        // a plain "sr"/"sr2" alias here would collide with the outer alias
        // and DQL would reject the query as "'sr' is already defined".
        $existsSub = $this->em->createQueryBuilder()
            ->select('__period_sr_exists.id')
            ->from(SeasonRecord::class, '__period_sr_exists')
            ->where("__period_sr_exists.club = {$clubAlias}.id");

        $maxSub = $this->em->createQueryBuilder()
            ->select('MAX(__period_sr_max.createdAt)')
            ->from(SeasonRecord::class, '__period_sr_max')
            ->where("__period_sr_max.club = {$clubAlias}.id");

        $qb->andWhere(new Orx([
            "NOT EXISTS ({$existsSub->getDQL()})",
            "{$alias}.{$dateField} >= ({$maxSub->getDQL()})",
        ]));
    }
}
