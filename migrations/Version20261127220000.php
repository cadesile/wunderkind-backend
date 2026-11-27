<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PA cheats (Courtney, 2026-11-27): the game_config switch (off by default, so deploying this
 * changes nothing in the game) and the club_illicit_activity table the sync fills.
 */
final class Version20261127220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PA cheats: game_config.pa_cheats_enabled + club_illicit_activity table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD pa_cheats_enabled BOOLEAN DEFAULT false NOT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE club_illicit_activity (
                id UUID NOT NULL,
                club_id UUID NOT NULL,
                client_id VARCHAR(64) NOT NULL,
                kind VARCHAR(32) NOT NULL,
                start_week INT NOT NULL,
                start_season INT NOT NULL,
                suspicion SMALLINT NOT NULL,
                status VARCHAR(16) NOT NULL,
                ended_week INT DEFAULT NULL,
                details JSON NOT NULL,
                first_seen_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                last_seen_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                last_sync_record_id UUID DEFAULT NULL,
                missing_since TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_club_illicit_activity_client ON club_illicit_activity (club_id, client_id)');
        $this->addSql('CREATE INDEX idx_club_illicit_activity_kind ON club_illicit_activity (kind)');
        $this->addSql('CREATE INDEX IDX_AE3A686B61190A32 ON club_illicit_activity (club_id)');
        $this->addSql('CREATE INDEX IDX_AE3A686BFF6D63F9 ON club_illicit_activity (last_sync_record_id)');
        $this->addSql('ALTER TABLE club_illicit_activity ADD CONSTRAINT FK_ILLICIT_ACTIVITY_CLUB FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE club_illicit_activity ADD CONSTRAINT FK_ILLICIT_ACTIVITY_SYNC_RECORD FOREIGN KEY (last_sync_record_id) REFERENCES sync_record (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE club_illicit_activity');
        $this->addSql('ALTER TABLE game_config DROP pa_cheats_enabled');
    }
}
