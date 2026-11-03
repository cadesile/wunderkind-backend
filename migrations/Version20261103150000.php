<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration B: the AI-enrichment history tables.
 *
 * `location_enrichment_run` (one batch) and `location_enrichment_attempt` (one provider call).
 * The location's CURRENT enrichment state already lives on `location` — added in
 * Version20261103120000 — so these two carry history rather than duplicating state, which is
 * why this is two tables and not WorldPackGenerationRun's three.
 *
 * Three decisions worth knowing:
 *
 * - `location_enrichment_attempt.run_id` is ON DELETE SET NULL, not CASCADE. Run headers are
 *   prunable operational records; the attempt history is the forensic trail that answers "why
 *   is this town priced like that", and it has to outlive them. Same reasoning as
 *   user_ledger.source_sync_record_id surviving a rollback purge.
 *
 * - `location_enrichment_run.requested_by_id` is SET NULL for the same reason, plus it is
 *   nullable anyway: a run triggered by the lazy read path has no admin behind it.
 *
 * - The column is `trigger_type`, not `trigger`. TRIGGER is a reserved SQL word; PostgreSQL
 *   accepts it unquoted (measured, not assumed) but this table is written to with raw SQL in
 *   LocationEnrichmentRunRepository::recordOutcome(), and the codebase already made this call
 *   once when LeaderboardEntry's `rank` became `rank_position`.
 *
 * `raw_response_json` is a split-row column in spirit: written once, read only by admin
 * forensics, and never to be hydrated on a page listing many locations. ORM 3 removed PARTIAL
 * hydration, so that rule is enforced by the repository having no bulk finder, not by the
 * schema.
 */
final class Version20261103150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Location AI enrichment history: location_enrichment_run, location_enrichment_attempt.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE location_enrichment_run (
                id UUID NOT NULL,
                trigger_type VARCHAR(16) NOT NULL,
                requested_by_id UUID DEFAULT NULL,
                country_code VARCHAR(2) DEFAULT NULL,
                provider VARCHAR(40) NOT NULL,
                model VARCHAR(60) NOT NULL,
                queued INT NOT NULL,
                succeeded INT NOT NULL,
                needs_review INT NOT NULL,
                failed INT NOT NULL,
                status VARCHAR(16) NOT NULL,
                started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_location_enrichment_run_started ON location_enrichment_run (started_at)');
        $this->addSql('CREATE INDEX IDX_13D39F254DA1E751 ON location_enrichment_run (requested_by_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE location_enrichment_attempt (
                id UUID NOT NULL,
                location_id UUID NOT NULL,
                run_id UUID DEFAULT NULL,
                provider VARCHAR(40) NOT NULL,
                model VARCHAR(60) NOT NULL,
                prompt_version VARCHAR(20) NOT NULL,
                raw_response_json JSON DEFAULT NULL,
                accepted_fields JSON DEFAULT NULL,
                rejected_fields JSON DEFAULT NULL,
                input_tokens INT DEFAULT NULL,
                output_tokens INT DEFAULT NULL,
                outcome VARCHAR(12) NOT NULL,
                error_message VARCHAR(255) DEFAULT NULL,
                duration_ms INT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_lea_location_created ON location_enrichment_attempt (location_id, created_at)');
        $this->addSql('CREATE INDEX idx_lea_run ON location_enrichment_attempt (run_id)');
        $this->addSql('CREATE INDEX idx_lea_outcome_created ON location_enrichment_attempt (outcome, created_at)');
        $this->addSql('CREATE INDEX IDX_EDD6E19B64D218E ON location_enrichment_attempt (location_id)');

        $this->addSql('ALTER TABLE location_enrichment_run ADD CONSTRAINT FK_13D39F254DA1E751 FOREIGN KEY (requested_by_id) REFERENCES admin (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE location_enrichment_attempt ADD CONSTRAINT FK_EDD6E19B64D218E FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE location_enrichment_attempt ADD CONSTRAINT FK_EDD6E19B84E3FEC4 FOREIGN KEY (run_id) REFERENCES location_enrichment_run (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE location_enrichment_attempt DROP CONSTRAINT FK_EDD6E19B84E3FEC4');
        $this->addSql('ALTER TABLE location_enrichment_attempt DROP CONSTRAINT FK_EDD6E19B64D218E');
        $this->addSql('ALTER TABLE location_enrichment_run DROP CONSTRAINT FK_13D39F254DA1E751');

        $this->addSql('DROP TABLE location_enrichment_attempt');
        $this->addSql('DROP TABLE location_enrichment_run');
    }
}
