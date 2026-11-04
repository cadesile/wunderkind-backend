<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `club_dormancy_warning` — the record that a player was told their plot was about to go.
 *
 * NOT THE COUNTDOWN. The countdown is computed from the club's activity clock, defined once in
 * PlotClaimRepository::findDormantClaims() as COALESCE(last_synced_at, created_at). This table
 * is the EVIDENCE that the warning was sent, and reclaiming a plot that carries real money
 * without that evidence is not defensible.
 *
 * THE UNIQUE KEY INCLUDES dormancy_cycle_key, AND THAT IS THE POINT. A player can lapse, come
 * back, and lapse again. Keyed on (claim_id, stage) alone, the second lapse would find a row
 * already there and silently send nothing — for ever — so a player would lose a plot having
 * been warned only about a cycle they had already recovered from. The key is the date of the
 * activity timestamp the cycle was computed from, so a fresh period of inactivity is a fresh
 * cycle with its own warnings. It is a plain unique (all three columns are NOT NULL), not a
 * partial one.
 *
 * inbox_message_id is a PLAIN VARCHAR WITH NO FOREIGN KEY. Inbox messages cascade away with
 * their club, and this record must outlive its own delivery receipt — the record is the
 * evidence, the message is only how it was delivered. Same reasoning as
 * leaderboard_period_archive_entry's own message reference.
 *
 * Both FKs CASCADE: a warning about a club or a claim that no longer exists has nothing left to
 * be evidence about.
 */
final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'club_dormancy_warning: evidence that a dormancy warning was sent, re-armable per dormancy cycle.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE club_dormancy_warning (
            id UUID NOT NULL,
            stage VARCHAR(16) NOT NULL,
            dormancy_cycle_key VARCHAR(10) NOT NULL,
            sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            inbox_message_id VARCHAR(36) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            club_id UUID NOT NULL,
            claim_id UUID NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE INDEX idx_dormancy_warning_club ON club_dormancy_warning (club_id)');
        $this->addSql('CREATE INDEX IDX_B276DD747096A49F ON club_dormancy_warning (claim_id)');
        $this->addSql(
            'CREATE UNIQUE INDEX uq_dormancy_warning_cycle
                 ON club_dormancy_warning (claim_id, stage, dormancy_cycle_key)',
        );

        $this->addSql('ALTER TABLE club_dormancy_warning
            ADD CONSTRAINT FK_B276DD7461190A32 FOREIGN KEY (club_id) REFERENCES club (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE club_dormancy_warning
            ADD CONSTRAINT FK_B276DD747096A49F FOREIGN KEY (claim_id) REFERENCES plot_claim (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS club_dormancy_warning');
    }
}
