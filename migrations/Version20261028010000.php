<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves the admin-only forensics off the rows that public endpoints read.
 *
 * competition_result.event_log_json and .computation_audit_json had exactly one reader
 * between them — the admin competition detail page — and CompetitionResult's own docblock
 * already said the audit was "Admin-only — never sent in toClientSummary()". The event log
 * was never sent either. Yet both were decoded on every public read of a bracket, every
 * GET /api/matches/{id} and every club-hub query, because a mapped column is always
 * hydrated and ORM 3 removed PARTIAL. friendly_challenge.event_log_json is the same story:
 * one admin reader, decoded for every COMPLETE challenge the public board lists.
 *
 * So an access boundary that existed only in a docblock is now in the schema:
 *
 *   competition_result_audit    event_log_json, computation_audit_json
 *   friendly_challenge_audit    event_log_json
 *
 * No backfill. The migration that cleared tournament and friendly instance data runs before
 * this one, so there are no rows to carry and event_log_json can be NOT NULL without a
 * synthetic default. On a database where that is somehow untrue the ALTERs below would fail
 * loudly rather than invent data, which is the right failure.
 *
 * These rows are deliberately **not** pruned when a competition completes, unlike entrant
 * squads. A squad is dead once no fixture can resolve; an audit exists precisely to answer
 * "how was this result derived" about a match already played, which is when anyone asks.
 * Ageing them out is a retention policy to decide separately, not cleanup to bundle in here.
 */
final class Version20261028010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split admin-only event logs and derivation audits into competition_result_audit and friendly_challenge_audit.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE competition_result_audit (
                id UUID NOT NULL,
                result_id UUID NOT NULL,
                event_log_json JSON NOT NULL,
                computation_audit_json JSON DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_competition_result_audit_result ON competition_result_audit (result_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE competition_result_audit
                ADD CONSTRAINT fk_competition_result_audit_result
                FOREIGN KEY (result_id) REFERENCES competition_result (id)
                ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE friendly_challenge_audit (
                id UUID NOT NULL,
                challenge_id UUID NOT NULL,
                event_log_json JSON NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_friendly_challenge_audit_challenge ON friendly_challenge_audit (challenge_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE friendly_challenge_audit
                ADD CONSTRAINT fk_friendly_challenge_audit_challenge
                FOREIGN KEY (challenge_id) REFERENCES friendly_challenge (id)
                ON DELETE CASCADE
        SQL);

        $this->addSql('ALTER TABLE competition_result DROP event_log_json');
        $this->addSql('ALTER TABLE competition_result DROP computation_audit_json');
        $this->addSql('ALTER TABLE friendly_challenge DROP event_log_json');
    }

    public function down(Schema $schema): void
    {
        // The columns come back empty. The rows that could have refilled them are in the
        // tables this drops, and a friendly's event log was never nullable-by-accident — it
        // is simply absent until a match is resolved.
        $this->addSql('ALTER TABLE competition_result ADD event_log_json JSON NOT NULL DEFAULT \'[]\'');
        $this->addSql('ALTER TABLE competition_result ALTER COLUMN event_log_json DROP DEFAULT');
        $this->addSql('ALTER TABLE competition_result ADD computation_audit_json JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE friendly_challenge ADD event_log_json JSON DEFAULT NULL');

        $this->addSql('ALTER TABLE competition_result_audit DROP CONSTRAINT fk_competition_result_audit_result');
        $this->addSql('DROP TABLE competition_result_audit');
        $this->addSql('ALTER TABLE friendly_challenge_audit DROP CONSTRAINT fk_friendly_challenge_audit_challenge');
        $this->addSql('DROP TABLE friendly_challenge_audit');
    }
}
