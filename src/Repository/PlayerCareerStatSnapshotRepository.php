<?php

namespace App\Repository;

use App\Entity\PlayerCareerStatSnapshot;
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
