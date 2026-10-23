<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023133500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create friendly_challenge and idempotency_record tables for Friendlies and API write idempotency.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE friendly_challenge (
                id UUID NOT NULL,
                challenger_club_id UUID DEFAULT NULL,
                acceptor_club_id UUID DEFAULT NULL,
                challenger_club_uuid VARCHAR(36) NOT NULL,
                acceptor_club_uuid VARCHAR(36) DEFAULT NULL,
                challenger_user_id VARCHAR(36) NOT NULL,
                acceptor_user_id VARCHAR(36) DEFAULT NULL,
                venue VARCHAR(10) NOT NULL,
                stake_pence BIGINT DEFAULT 0 NOT NULL,
                status VARCHAR(255) NOT NULL,
                challenger_ovr SMALLINT NOT NULL,
                challenger_reputation SMALLINT NOT NULL,
                challenger_reputation_tier VARCHAR(20) NOT NULL,
                challenger_snapshot_json JSON NOT NULL,
                acceptor_snapshot_json JSON DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                kickoff_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                resolve_locked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                home_score SMALLINT DEFAULT NULL,
                away_score SMALLINT DEFAULT NULL,
                home_club_json JSON DEFAULT NULL,
                away_club_json JSON DEFAULT NULL,
                home_lineup_json JSON DEFAULT NULL,
                away_lineup_json JSON DEFAULT NULL,
                event_log_json JSON DEFAULT NULL,
                narrative_payload JSON DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_friendly_challenge_status_expires ON friendly_challenge (status, expires_at)');
        $this->addSql('CREATE INDEX idx_friendly_challenge_status_kickoff ON friendly_challenge (status, kickoff_at)');
        $this->addSql('CREATE INDEX idx_friendly_challenge_challenger_status ON friendly_challenge (challenger_club_id, status)');
        $this->addSql('CREATE INDEX idx_friendly_challenge_acceptor_status ON friendly_challenge (acceptor_club_id, status)');
        $this->addSql("COMMENT ON COLUMN friendly_challenge.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN friendly_challenge.challenger_club_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN friendly_challenge.acceptor_club_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN friendly_challenge.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN friendly_challenge.expires_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN friendly_challenge.accepted_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN friendly_challenge.kickoff_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN friendly_challenge.resolved_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN friendly_challenge.resolve_locked_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql('ALTER TABLE friendly_challenge ADD CONSTRAINT FK_FRIENDLY_CHALLENGER_CLUB FOREIGN KEY (challenger_club_id) REFERENCES club (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE friendly_challenge ADD CONSTRAINT FK_FRIENDLY_ACCEPTOR_CLUB FOREIGN KEY (acceptor_club_id) REFERENCES club (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            CREATE TABLE idempotency_record (
                id UUID NOT NULL,
                user_id VARCHAR(36) NOT NULL,
                method VARCHAR(10) NOT NULL,
                path VARCHAR(255) NOT NULL,
                idempotency_key VARCHAR(120) NOT NULL,
                request_body_hash VARCHAR(64) NOT NULL,
                response_status SMALLINT NOT NULL,
                response_body TEXT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_idempotency_record_user_method_path_key ON idempotency_record (user_id, method, path, idempotency_key)');
        $this->addSql('CREATE INDEX idx_idempotency_record_created_at ON idempotency_record (created_at)');
        $this->addSql("COMMENT ON COLUMN idempotency_record.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN idempotency_record.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE friendly_challenge');
        $this->addSql('DROP TABLE idempotency_record');
    }
}
