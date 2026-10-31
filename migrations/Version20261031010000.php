<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Turns every non-'all-time' leaderboard_entry row into a GROWTH row, by adding the two
 * columns that make growth expressible and resetting the boards currently in flight.
 *
 * Why two columns and not one. LeaderboardEntry.score is an absolute snapshot of a
 * cumulative total, re-read and rewritten on every observation — it has never been an
 * accumulator. SyncService writes Club::getReputation() into both the 'all-time' row and
 * the ISO-week row (the same number), and LeaderboardCalculationService writes career
 * totals from PlayerCareerStat / UserLedger / SeasonRecord. So *deleting* rows at a period
 * boundary resets nothing: the next sync and the next five-minute cron tick put the
 * identical cumulative numbers straight back. The existing weekly board is the proof — it
 * gets a fresh period key every ISO week and still shows all-time career goals.
 *
 * The only thing that resets a score is a baseline, so a period row now keeps three
 * numbers: baseline_score (the cumulative when this period first saw this club),
 * cumulative_score (the truthful cumulative at the last observation) and score
 * (max(0, cumulative - baseline) — the growth, which is what the board ranks). Only the
 * cumulative chains between periods, so growth never compounds its own clamping, and the
 * clamp exists because score is BIGINT UNSIGNED while growth genuinely goes negative
 * (club_reputation decays daily via app:reputation:decay).
 *
 * Both columns live on leaderboard_entry rather than in a baseline table because this
 * table already holds exactly one row per (club, category, period) — the correct grain —
 * so the baseline costs zero extra rows. They are nullable because 'all-time' rows are
 * never growth-scored, and because a baseline is stamped lazily, at a club's first
 * observation inside a period.
 *
 * Why no backfill, and why the reset is not one. There is nothing to backfill: a baseline
 * is a statement about when a period started observing a club, and for every period
 * already underway that information was never recorded. What the UPDATE below does instead
 * is make the discontinuity explicit and one-off — every in-flight period row is declared
 * to start its growth measurement HERE, at its current cumulative. Without it those rows
 * would sit at a stale cumulative score with a NULL baseline until each was next observed,
 * and assignRanks() would rank the whole board on that stale cumulative in the meantime —
 * i.e. the old standing masquerading as growth. Visible effect on deploy: the current ISO
 * week's boards read zero and climb from there, which is the intended semantics arriving a
 * few days early.
 *
 * 'all-time' rows are deliberately untouched: they are standings, they keep using
 * setScore(), and their two new columns stay NULL forever.
 */
final class Version20261031010000 extends AbstractMigration
{
    /**
     * The one-off reset, as a constant so LeaderboardGrowthResetTest can exercise the SQL
     * this migration actually ships rather than a hand-copied paraphrase of it. The
     * wunderkind_test database is built by doctrine:schema:create, not by migrations, so
     * the statement never runs there on its own.
     */
    public const RESET_IN_FLIGHT_PERIODS_SQL = <<<'SQL'
        UPDATE leaderboard_entry
           SET baseline_score = score,
               cumulative_score = score,
               score = 0
         WHERE period <> 'all-time'
        SQL;

    public function getDescription(): string
    {
        return 'Add baseline_score/cumulative_score to leaderboard_entry and reset in-flight period boards to zero growth.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leaderboard_entry ADD baseline_score BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE leaderboard_entry ADD cumulative_score BIGINT DEFAULT NULL');

        $this->addSql(self::RESET_IN_FLIGHT_PERIODS_SQL);
    }

    public function down(Schema $schema): void
    {
        // Restore the cumulative into score before dropping the columns, so a rollback
        // leaves period rows holding the standing they held before this migration rather
        // than the zeroed growth. A row never observed since the upgrade has a NULL
        // cumulative and keeps whatever score it has.
        $this->addSql(<<<'SQL'
            UPDATE leaderboard_entry
               SET score = cumulative_score
             WHERE period <> 'all-time'
               AND cumulative_score IS NOT NULL
            SQL);

        $this->addSql('ALTER TABLE leaderboard_entry DROP cumulative_score');
        $this->addSql('ALTER TABLE leaderboard_entry DROP baseline_score');
    }
}
