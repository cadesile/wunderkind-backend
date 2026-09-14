<?php

namespace App\Repository;

use App\Entity\PlayerCareerStatSnapshot;
use App\Enum\StatsPeriod;
use App\Service\PeriodResolver;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reconstructs true career (all-time) totals from the append-only snapshot history,
 * correctly handling season resets — see PlayerCareerStatSnapshot and
 * LeaderboardCalculationService for why a plain SUM/MAX over PlayerCareerStat's
 * current value is wrong once a club has played more than one season.
 */
class PlayerCareerStatSnapshotRepository extends ServiceEntityRepository
{
    private const COLUMNS = ['goals', 'assists', 'appearances'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerCareerStatSnapshot::class);
    }

    /**
     * Career total per club — the sum, across every player who has ever played for the
     * club, of that player's reset-aware career total for $column.
     *
     * @param 'goals'|'assists'|'appearances' $column
     * @return array<array{clubId: string, score: int, displayLabel: null}>
     */
    public function sumCareerTotalsByClub(string $column): array
    {
        $totals = [];
        foreach ($this->playerCareerTotals($column) as $row) {
            $clubId = $row['club_id'];
            $totals[$clubId] = ($totals[$clubId] ?? 0) + (int) $row['total'];
        }

        return array_map(
            static fn (string $clubId, int $score): array => ['clubId' => $clubId, 'score' => $score, 'displayLabel' => null],
            array_keys($totals),
            array_values($totals),
        );
    }

    /**
     * Each club's single best career performer for $column — the player with the
     * highest reset-aware career total, named via displayLabel.
     *
     * @param 'goals'|'assists'|'appearances' $column
     * @return array<array{clubId: string, score: int, displayLabel: string}>
     */
    public function topCareerPerformerByClub(string $column): array
    {
        $best = [];
        foreach ($this->playerCareerTotals($column) as $row) {
            $clubId = $row['club_id'];
            $total  = (int) $row['total'];
            if (!isset($best[$clubId]) || $total > $best[$clubId]['score']) {
                $best[$clubId] = ['clubId' => $clubId, 'score' => $total, 'displayLabel' => $row['player_name']];
            }
        }

        return array_values($best);
    }

    /**
     * Top goal-scorer per club within a real-world time window — backs the
     * SUPER_STRIKER category. Extends the reset-aware CTE pattern from
     * playerCareerTotals() with three deliberate differences, each load-bearing:
     *
     * 1. Reset-detection (LAG()) still runs over the FULL unfiltered snapshot history
     *    per (club, player) in the `ordered` CTE — a season reset just before or inside
     *    the window must still be caught, or it would be misread as a huge negative
     *    delta (or, per the existing reset-handling CASE, correctly re-counted as fresh
     *    activity — but only if the prior value is visible at all).
     * 2. The window bound is applied only when SUMMING deltas into a total (in `totals`),
     *    never earlier — filtering `ordered`/`deltas` by the window first would hide the
     *    pre-window snapshot LAG() needs to detect a reset correctly.
     * 3. Unlike playerCareerTotals()'s private helper (whose only callers don't need a
     *    club name), this method JOINs `club` to project `clubName`, because
     *    CommunityStatsService::rank() requires it on every row.
     *
     * SEASON is not one of PeriodResolver's fixed offsets — its bound is per-club (each
     * club's own latest SeasonRecord.createdAt), so it's handled as a separate SQL
     * template (a LEFT JOIN against a per-club bound) rather than a bound parameter.
     *
     * @return array<array{clubId: string, clubName: string, value: int, playerName: string}>
     */
    public function topGoalScorerInWindowByClub(StatsPeriod $period, int $limit, PeriodResolver $resolver): array
    {
        $connection = $this->getEntityManager()->getConnection();

        $ordered = <<<'SQL'
            ordered AS (
                SELECT
                    club_id,
                    player_id,
                    player_name,
                    goals AS value,
                    recorded_at,
                    LAG(goals) OVER (PARTITION BY club_id, player_id ORDER BY recorded_at, id) AS prev_value,
                    ROW_NUMBER() OVER (PARTITION BY club_id, player_id ORDER BY recorded_at DESC, id DESC) AS rn_desc
                FROM player_career_stat_snapshot
            ),
            deltas AS (
                SELECT
                    club_id,
                    player_id,
                    recorded_at,
                    CASE
                        WHEN prev_value IS NULL THEN value
                        WHEN value >= prev_value THEN value - prev_value
                        ELSE value
                    END AS delta
                FROM ordered
            )
            SQL;

        $select = <<<'SQL'
            SELECT t.club_id::text AS club_id, cl.name AS club_name, t.player_id, o.player_name, t.total
            FROM totals t
            JOIN ordered o ON o.club_id = t.club_id AND o.player_id = t.player_id AND o.rn_desc = 1
            JOIN club cl ON cl.id = t.club_id
            SQL;

        if ($period === StatsPeriod::SEASON) {
            $sql = <<<SQL
                WITH club_season_bound AS (
                    SELECT club_id, MAX(created_at) AS bound
                    FROM season_record
                    GROUP BY club_id
                ),
                {$ordered},
                totals AS (
                    SELECT d.club_id, d.player_id, SUM(d.delta) AS total
                    FROM deltas d
                    LEFT JOIN club_season_bound csb ON csb.club_id = d.club_id
                    WHERE csb.bound IS NULL OR d.recorded_at >= csb.bound
                    GROUP BY d.club_id, d.player_id
                )
                {$select}
                SQL;
            $rows = $connection->fetchAllAssociative($sql);
        } else {
            $windowStart = $resolver->resolveFixedLowerBound($period);
            $sql = <<<SQL
                WITH {$ordered},
                totals AS (
                    SELECT club_id, player_id, SUM(delta) AS total
                    FROM deltas
                    WHERE (:windowStart::timestamp IS NULL OR recorded_at >= :windowStart::timestamp)
                    GROUP BY club_id, player_id
                )
                {$select}
                SQL;
            $rows = $connection->fetchAllAssociative($sql, [
                'windowStart' => $windowStart?->format('Y-m-d H:i:s'),
            ]);
        }

        $best = [];
        foreach ($rows as $row) {
            $clubId = $row['club_id'];
            $total  = (int) $row['total'];
            if (!isset($best[$clubId]) || $total > $best[$clubId]['value']) {
                $best[$clubId] = [
                    'clubId'     => $clubId,
                    'clubName'   => $row['club_name'],
                    'value'      => $total,
                    'playerName' => $row['player_name'],
                ];
            }
        }

        usort($best, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return array_slice(array_values($best), 0, $limit);
    }

    /**
     * One row per (club, player): their reset-aware career total for $column, computed
     * from the full ordered snapshot history. A season reset shows up as a value lower
     * than the previous snapshot; when that happens the new (lower) value itself is
     * counted as fresh activity rather than subtracted as a negative delta — the same
     * pattern used by counter-reset-aware rate() functions.
     *
     * @param 'goals'|'assists'|'appearances' $column
     * @return array<array{club_id: string, player_id: string, player_name: string, total: string}>
     */
    private function playerCareerTotals(string $column): array
    {
        if (!in_array($column, self::COLUMNS, true)) {
            throw new \InvalidArgumentException("Unsupported column: {$column}");
        }

        // $column is whitelisted above, not user input — safe to interpolate.
        $sql = <<<SQL
            WITH ordered AS (
                SELECT
                    club_id,
                    player_id,
                    player_name,
                    {$column} AS value,
                    LAG({$column}) OVER (PARTITION BY club_id, player_id ORDER BY recorded_at, id) AS prev_value,
                    ROW_NUMBER() OVER (PARTITION BY club_id, player_id ORDER BY recorded_at DESC, id DESC) AS rn_desc
                FROM player_career_stat_snapshot
            ),
            deltas AS (
                SELECT
                    club_id,
                    player_id,
                    CASE
                        WHEN prev_value IS NULL THEN value
                        WHEN value >= prev_value THEN value - prev_value
                        ELSE value
                    END AS delta
                FROM ordered
            ),
            totals AS (
                SELECT club_id, player_id, SUM(delta) AS total
                FROM deltas
                GROUP BY club_id, player_id
            )
            SELECT t.club_id::text AS club_id, t.player_id, o.player_name, t.total
            FROM totals t
            JOIN ordered o ON o.club_id = t.club_id AND o.player_id = t.player_id AND o.rn_desc = 1
            SQL;

        return $this->getEntityManager()->getConnection()->fetchAllAssociative($sql);
    }
}
