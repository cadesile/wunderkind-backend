<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260301214628 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: User, Academy, Player (with PersonalityProfile), Guardian, Agent, Staff, Transfer, SyncRecord, LeaderboardEntry';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agent (id BINARY(16) NOT NULL, name VARCHAR(100) NOT NULL, is_universal TINYINT NOT NULL, reputation INT UNSIGNED DEFAULT 50 NOT NULL, commission_rate NUMERIC(5, 2) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE academy (id BINARY(16) NOT NULL, name VARCHAR(100) NOT NULL, reputation INT UNSIGNED DEFAULT 0 NOT NULL, total_career_earnings BIGINT UNSIGNED DEFAULT 0 NOT NULL, hall_of_fame_points INT UNSIGNED DEFAULT 0 NOT NULL, last_synced_week INT UNSIGNED DEFAULT 0 NOT NULL, last_synced_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_994D0ECBA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE guardian (id BINARY(16) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, contact_email VARCHAR(180) DEFAULT NULL, demand_level SMALLINT UNSIGNED DEFAULT 5 NOT NULL, loyalty_to_academy SMALLINT UNSIGNED DEFAULT 50 NOT NULL, player_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_6448605599E6F5DF (player_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sync_record (id BINARY(16) NOT NULL, client_week_number INT UNSIGNED NOT NULL, client_timestamp DATETIME NOT NULL, server_timestamp DATETIME NOT NULL, payload JSON NOT NULL, is_valid TINYINT NOT NULL, invalid_reason VARCHAR(255) DEFAULT NULL, academy_id BINARY(16) NOT NULL, INDEX IDX_33B5714E6D55ACAB (academy_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE transfer (id BINARY(16) NOT NULL, destination_club_name VARCHAR(100) NOT NULL, type VARCHAR(255) NOT NULL, fee INT UNSIGNED DEFAULT 0 NOT NULL, agent_commission INT UNSIGNED DEFAULT 0 NOT NULL, occurred_at DATETIME NOT NULL, synced_at DATETIME DEFAULT NULL, player_id BINARY(16) NOT NULL, academy_id BINARY(16) NOT NULL, INDEX IDX_4034A3C099E6F5DF (player_id), INDEX IDX_4034A3C06D55ACAB (academy_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE leaderboard_entry (id BINARY(16) NOT NULL, category VARCHAR(255) NOT NULL, score BIGINT UNSIGNED DEFAULT 0 NOT NULL, period VARCHAR(20) NOT NULL, rank_position INT UNSIGNED DEFAULT NULL, updated_at DATETIME NOT NULL, academy_id BINARY(16) NOT NULL, INDEX IDX_3B08BDDB6D55ACAB (academy_id), UNIQUE INDEX uq_leaderboard_academy_category_period (academy_id, category, period), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE staff (id BINARY(16) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, role VARCHAR(255) NOT NULL, coaching_ability SMALLINT UNSIGNED DEFAULT 50 NOT NULL, scouting_range SMALLINT UNSIGNED DEFAULT 50 NOT NULL, weekly_salary INT UNSIGNED DEFAULT 0 NOT NULL, hired_at DATETIME NOT NULL, academy_id BINARY(16) NOT NULL, INDEX IDX_426EF3926D55ACAB (academy_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE player (id BINARY(16) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, date_of_birth DATE NOT NULL, nationality VARCHAR(60) NOT NULL, position VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, recruitment_source VARCHAR(255) NOT NULL, potential SMALLINT UNSIGNED NOT NULL, current_ability SMALLINT UNSIGNED NOT NULL, contract_value INT UNSIGNED DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, personality_confidence SMALLINT UNSIGNED DEFAULT 50 NOT NULL, personality_maturity SMALLINT UNSIGNED DEFAULT 50 NOT NULL, personality_teamwork SMALLINT UNSIGNED DEFAULT 50 NOT NULL, personality_leadership SMALLINT UNSIGNED DEFAULT 50 NOT NULL, personality_ego SMALLINT UNSIGNED DEFAULT 50 NOT NULL, personality_bravery SMALLINT UNSIGNED DEFAULT 50 NOT NULL, personality_greed SMALLINT UNSIGNED DEFAULT 50 NOT NULL, personality_loyalty SMALLINT UNSIGNED DEFAULT 50 NOT NULL, academy_id BINARY(16) NOT NULL, agent_id BINARY(16) DEFAULT NULL, INDEX IDX_98197A656D55ACAB (academy_id), INDEX IDX_98197A653414710B (agent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE player_siblings (player_source BINARY(16) NOT NULL, player_target BINARY(16) NOT NULL, INDEX IDX_A12DB694C08AE9AD (player_source), INDEX IDX_A12DB694D96FB922 (player_target), PRIMARY KEY (player_source, player_target)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE academy ADD CONSTRAINT FK_994D0ECBA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE guardian ADD CONSTRAINT FK_6448605599E6F5DF FOREIGN KEY (player_id) REFERENCES player (id)');
        $this->addSql('ALTER TABLE sync_record ADD CONSTRAINT FK_33B5714E6D55ACAB FOREIGN KEY (academy_id) REFERENCES academy (id)');
        $this->addSql('ALTER TABLE transfer ADD CONSTRAINT FK_4034A3C099E6F5DF FOREIGN KEY (player_id) REFERENCES player (id)');
        $this->addSql('ALTER TABLE transfer ADD CONSTRAINT FK_4034A3C06D55ACAB FOREIGN KEY (academy_id) REFERENCES academy (id)');
        $this->addSql('ALTER TABLE leaderboard_entry ADD CONSTRAINT FK_3B08BDDB6D55ACAB FOREIGN KEY (academy_id) REFERENCES academy (id)');
        $this->addSql('ALTER TABLE staff ADD CONSTRAINT FK_426EF3926D55ACAB FOREIGN KEY (academy_id) REFERENCES academy (id)');
        $this->addSql('ALTER TABLE player ADD CONSTRAINT FK_98197A656D55ACAB FOREIGN KEY (academy_id) REFERENCES academy (id)');
        $this->addSql('ALTER TABLE player ADD CONSTRAINT FK_98197A653414710B FOREIGN KEY (agent_id) REFERENCES agent (id)');
        $this->addSql('ALTER TABLE player_siblings ADD CONSTRAINT FK_A12DB694C08AE9AD FOREIGN KEY (player_source) REFERENCES player (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE player_siblings ADD CONSTRAINT FK_A12DB694D96FB922 FOREIGN KEY (player_target) REFERENCES player (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE academy DROP FOREIGN KEY FK_994D0ECBA76ED395');
        $this->addSql('ALTER TABLE guardian DROP FOREIGN KEY FK_6448605599E6F5DF');
        $this->addSql('ALTER TABLE sync_record DROP FOREIGN KEY FK_33B5714E6D55ACAB');
        $this->addSql('ALTER TABLE transfer DROP FOREIGN KEY FK_4034A3C099E6F5DF');
        $this->addSql('ALTER TABLE transfer DROP FOREIGN KEY FK_4034A3C06D55ACAB');
        $this->addSql('ALTER TABLE leaderboard_entry DROP FOREIGN KEY FK_3B08BDDB6D55ACAB');
        $this->addSql('ALTER TABLE staff DROP FOREIGN KEY FK_426EF3926D55ACAB');
        $this->addSql('ALTER TABLE player DROP FOREIGN KEY FK_98197A656D55ACAB');
        $this->addSql('ALTER TABLE player DROP FOREIGN KEY FK_98197A653414710B');
        $this->addSql('ALTER TABLE player_siblings DROP FOREIGN KEY FK_A12DB694C08AE9AD');
        $this->addSql('ALTER TABLE player_siblings DROP FOREIGN KEY FK_A12DB694D96FB922');
        $this->addSql('DROP TABLE leaderboard_entry');
        $this->addSql('DROP TABLE sync_record');
        $this->addSql('DROP TABLE transfer');
        $this->addSql('DROP TABLE staff');
        $this->addSql('DROP TABLE guardian');
        $this->addSql('DROP TABLE player_siblings');
        $this->addSql('DROP TABLE player');
        $this->addSql('DROP TABLE agent');
        $this->addSql('DROP TABLE academy');
        $this->addSql('DROP TABLE `user`');
    }
}
