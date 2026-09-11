<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260911223342 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add player_career_stat_snapshot (append-only history behind PlayerCareerStatSnapshot)';
    }

    public function up(Schema $schema): void
    {
        // Pre-existing, unrelated schema drift (excursion/game_config/starter_config default
        // drops, index renames) was excluded from this migration by hand — doctrine:migrations:diff
        // picked it up from the working DB, but it predates this change and isn't part of it.
        $this->addSql('CREATE TABLE player_career_stat_snapshot (id UUID NOT NULL, player_id VARCHAR(64) NOT NULL, player_name VARCHAR(100) NOT NULL, appearances INT DEFAULT 0 NOT NULL, goals INT DEFAULT 0 NOT NULL, assists INT DEFAULT 0 NOT NULL, recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, club_id UUID NOT NULL, sync_record_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_DF3696ED61190A32 ON player_career_stat_snapshot (club_id)');
        $this->addSql('CREATE INDEX IDX_DF3696ED916A6A7D ON player_career_stat_snapshot (sync_record_id)');
        $this->addSql('CREATE INDEX idx_player_career_stat_snapshot_club_player_recorded ON player_career_stat_snapshot (club_id, player_id, recorded_at)');
        $this->addSql('ALTER TABLE player_career_stat_snapshot ADD CONSTRAINT FK_DF3696ED61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player_career_stat_snapshot ADD CONSTRAINT FK_DF3696ED916A6A7D FOREIGN KEY (sync_record_id) REFERENCES sync_record (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player_career_stat_snapshot DROP CONSTRAINT FK_DF3696ED61190A32');
        $this->addSql('ALTER TABLE player_career_stat_snapshot DROP CONSTRAINT FK_DF3696ED916A6A7D');
        $this->addSql('DROP TABLE player_career_stat_snapshot');
    }
}
