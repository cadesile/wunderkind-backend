<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260414120911 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE league (id UUID NOT NULL, country VARCHAR(2) NOT NULL, tier SMALLINT NOT NULL, name VARCHAR(100) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_league_country_tier ON league (country, tier)');
        $this->addSql('CREATE TABLE match_result (id UUID NOT NULL, goals_for SMALLINT NOT NULL, goals_against SMALLINT NOT NULL, week SMALLINT NOT NULL, season SMALLINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, academy_id UUID NOT NULL, opponent_club_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B20538126D55ACAB ON match_result (academy_id)');
        $this->addSql('CREATE INDEX IDX_B20538126C52D03A ON match_result (opponent_club_id)');
        $this->addSql('CREATE TABLE season_record (id UUID NOT NULL, season SMALLINT NOT NULL, final_position SMALLINT NOT NULL, games_played SMALLINT NOT NULL, wins SMALLINT NOT NULL, draws SMALLINT NOT NULL, losses SMALLINT NOT NULL, goals_for SMALLINT NOT NULL, goals_against SMALLINT NOT NULL, points SMALLINT NOT NULL, promoted BOOLEAN NOT NULL, relegated BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, academy_id UUID NOT NULL, league_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8D4F61236D55ACAB ON season_record (academy_id)');
        $this->addSql('CREATE INDEX IDX_8D4F612358AFC4DE ON season_record (league_id)');
        $this->addSql('CREATE TABLE season_snapshot (id UUID NOT NULL, season SMALLINT NOT NULL, country VARCHAR(2) NOT NULL, snapshot_data JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, academy_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A0BB106F6D55ACAB ON season_snapshot (academy_id)');
        $this->addSql('ALTER TABLE match_result ADD CONSTRAINT FK_B20538126D55ACAB FOREIGN KEY (academy_id) REFERENCES academy (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE match_result ADD CONSTRAINT FK_B20538126C52D03A FOREIGN KEY (opponent_club_id) REFERENCES npc_club (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE season_record ADD CONSTRAINT FK_8D4F61236D55ACAB FOREIGN KEY (academy_id) REFERENCES academy (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE season_record ADD CONSTRAINT FK_8D4F612358AFC4DE FOREIGN KEY (league_id) REFERENCES league (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE season_snapshot ADD CONSTRAINT FK_A0BB106F6D55ACAB FOREIGN KEY (academy_id) REFERENCES academy (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE academy ADD current_season SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE academy ADD current_league_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE academy ADD CONSTRAINT FK_994D0ECB83897572 FOREIGN KEY (current_league_id) REFERENCES league (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_994D0ECB83897572 ON academy (current_league_id)');
        $this->addSql('ALTER TABLE npc_club ADD league_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE npc_club ADD CONSTRAINT FK_E1A27F4B58AFC4DE FOREIGN KEY (league_id) REFERENCES league (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_E1A27F4B58AFC4DE ON npc_club (league_id)');
        $this->addSql("ALTER TABLE starter_config ADD default_facilities JSON NOT NULL DEFAULT '{}'");
        $this->addSql("ALTER TABLE starter_config ADD starter_reputation_tier VARCHAR(20) NOT NULL DEFAULT 'local'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE match_result DROP CONSTRAINT FK_B20538126D55ACAB');
        $this->addSql('ALTER TABLE match_result DROP CONSTRAINT FK_B20538126C52D03A');
        $this->addSql('ALTER TABLE season_record DROP CONSTRAINT FK_8D4F61236D55ACAB');
        $this->addSql('ALTER TABLE season_record DROP CONSTRAINT FK_8D4F612358AFC4DE');
        $this->addSql('ALTER TABLE season_snapshot DROP CONSTRAINT FK_A0BB106F6D55ACAB');
        $this->addSql('DROP TABLE league');
        $this->addSql('DROP TABLE match_result');
        $this->addSql('DROP TABLE season_record');
        $this->addSql('DROP TABLE season_snapshot');
        $this->addSql('ALTER TABLE academy DROP CONSTRAINT FK_994D0ECB83897572');
        $this->addSql('DROP INDEX IDX_994D0ECB83897572');
        $this->addSql('ALTER TABLE academy DROP current_season');
        $this->addSql('ALTER TABLE academy DROP current_league_id');
        $this->addSql('ALTER TABLE npc_club DROP CONSTRAINT FK_E1A27F4B58AFC4DE');
        $this->addSql('DROP INDEX IDX_E1A27F4B58AFC4DE');
        $this->addSql('ALTER TABLE npc_club DROP league_id');
        $this->addSql('ALTER TABLE starter_config DROP default_facilities');
        $this->addSql('ALTER TABLE starter_config DROP starter_reputation_tier');
    }
}
