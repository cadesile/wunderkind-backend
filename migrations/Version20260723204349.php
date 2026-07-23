<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260723204349 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Advanced Leaderboard API: club fan/attendance fields, ClubFacility and PlayerCareerStat tables, LeaderboardEntry.display_label';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE club_facility (id UUID NOT NULL, facility_slug VARCHAR(60) NOT NULL, level SMALLINT DEFAULT 0 NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, club_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_13E23E1B61190A32 ON club_facility (club_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_club_facility_slug ON club_facility (club_id, facility_slug)');
        $this->addSql('CREATE TABLE player_career_stat (id UUID NOT NULL, player_id VARCHAR(64) NOT NULL, player_name VARCHAR(100) NOT NULL, appearances INT DEFAULT 0 NOT NULL, goals INT DEFAULT 0 NOT NULL, assists INT DEFAULT 0 NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, club_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A626352D61190A32 ON player_career_stat (club_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_player_career_stat_club_player ON player_career_stat (club_id, player_id)');
        $this->addSql('CREATE INDEX idx_player_career_stat_goals ON player_career_stat (goals)');
        $this->addSql('CREATE INDEX idx_player_career_stat_assists ON player_career_stat (assists)');
        $this->addSql('ALTER TABLE club_facility ADD CONSTRAINT FK_13E23E1B61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE player_career_stat ADD CONSTRAINT FK_A626352D61190A32 FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE club ADD fan_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE club ADD fan_sentiment SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE club ADD fan_morale SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE club ADD last_weekly_attendance INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE club ADD total_season_attendance BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE leaderboard_entry ADD display_label VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE club_facility DROP CONSTRAINT FK_13E23E1B61190A32');
        $this->addSql('ALTER TABLE player_career_stat DROP CONSTRAINT FK_A626352D61190A32');
        $this->addSql('DROP INDEX idx_player_career_stat_goals');
        $this->addSql('DROP INDEX idx_player_career_stat_assists');
        $this->addSql('DROP TABLE club_facility');
        $this->addSql('DROP TABLE player_career_stat');
        $this->addSql('ALTER TABLE club DROP fan_count');
        $this->addSql('ALTER TABLE club DROP fan_sentiment');
        $this->addSql('ALTER TABLE club DROP fan_morale');
        $this->addSql('ALTER TABLE club DROP last_weekly_attendance');
        $this->addSql('ALTER TABLE club DROP total_season_attendance');
        $this->addSql('ALTER TABLE leaderboard_entry DROP display_label');
    }
}
