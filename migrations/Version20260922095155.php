<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Decouples the competition round lifecycle's draw and resolve phases (previously fused
 * into one CompetitionRoundProcessorService pass) into two independently-scheduled,
 * independently-claimed steps.
 *
 * competition_round:
 *  - locked_for_processing_at is renamed to draw_locked_at (same claim-column idiom,
 *    now specifically the draw-phase claim) via RENAME COLUMN, not drop+add, so any
 *    in-flight claim value survives.
 *  - resolve_locked_at is added: the resolve-phase's own claim column — a round is now
 *    claimed twice in its life (once per phase), so one shared claim column can't safely
 *    serve both (the second claim would be indistinguishable from "already drawn").
 *  - matches_resolve_at is added: this round's resolve due-time, written the instant it's
 *    drawn. scheduledAt now means "this round's DRAW due-time" only.
 *  - status values are remapped: pending/scheduled -> draw_pending, running -> drawn,
 *    completed -> results_published (cancelled is unchanged) — see
 *    App\Enum\Competition\CompetitionRoundStatus.
 *  - a new (status, matches_resolve_at) index backs the resolve-due query, alongside the
 *    existing (status, scheduled_at) draw-due index.
 *
 * competition_template:
 *  - intermission_ratio is added: fraction of each round's time-budget spent in the
 *    post-results intermission before the next draw (also governs the lead time before
 *    round 1's own draw) — see App\Service\Competition\CompetitionScheduleCalculator.
 */
final class Version20260922095155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Decouple competition round draw/resolve phases: split claim columns, add matches_resolve_at + intermission_ratio, remap round status values';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_round RENAME COLUMN locked_for_processing_at TO draw_locked_at');
        $this->addSql('ALTER TABLE competition_round ADD resolve_locked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE competition_round ADD matches_resolve_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_competition_round_status_resolve ON competition_round (status, matches_resolve_at)');

        $this->addSql(<<<'SQL'
            UPDATE competition_round SET status = CASE status
                WHEN 'pending' THEN 'draw_pending'
                WHEN 'scheduled' THEN 'draw_pending'
                WHEN 'running' THEN 'drawn'
                WHEN 'completed' THEN 'results_published'
                ELSE status
            END
            WHERE status IN ('pending', 'scheduled', 'running', 'completed')
            SQL);

        $this->addSql('ALTER TABLE competition_template ADD intermission_ratio DOUBLE PRECISION DEFAULT 0.3 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition_template DROP intermission_ratio');

        $this->addSql(<<<'SQL'
            UPDATE competition_round SET status = CASE status
                WHEN 'draw_pending' THEN 'pending'
                WHEN 'drawn' THEN 'running'
                WHEN 'results_published' THEN 'completed'
                ELSE status
            END
            WHERE status IN ('draw_pending', 'drawn', 'results_published')
            SQL);

        $this->addSql('DROP INDEX idx_competition_round_status_resolve');
        $this->addSql('ALTER TABLE competition_round DROP matches_resolve_at');
        $this->addSql('ALTER TABLE competition_round DROP resolve_locked_at');
        $this->addSql('ALTER TABLE competition_round RENAME COLUMN draw_locked_at TO locked_for_processing_at');
    }
}
