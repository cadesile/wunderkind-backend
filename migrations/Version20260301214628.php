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
        $this->addSql(<<<'SQL'
            CREATE TABLE `user` (
                id          VARCHAR(36)  NOT NULL,
                email       VARCHAR(180) NOT NULL,
                password    VARCHAR(255) NOT NULL,
                roles       JSON         NOT NULL,
                created_at  DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                UNIQUE INDEX uq_user_email (email)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE academy (
                id                    VARCHAR(36)  NOT NULL,
                user_id               VARCHAR(36)  NOT NULL,
                name                  VARCHAR(100) NOT NULL,
                reputation            INT UNSIGNED NOT NULL DEFAULT 0,
                total_career_earnings BIGINT UNSIGNED NOT NULL DEFAULT 0,
                hall_of_fame_points   INT UNSIGNED NOT NULL DEFAULT 0,
                last_synced_week      INT UNSIGNED NOT NULL DEFAULT 0,
                last_synced_at        DATETIME     NULL     COMMENT '(DC2Type:datetime_immutable)',
                created_at            DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                UNIQUE INDEX uq_academy_user (user_id),
                CONSTRAINT fk_academy_user FOREIGN KEY (user_id) REFERENCES `user` (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE agent (
                id              VARCHAR(36)    NOT NULL,
                name            VARCHAR(100)   NOT NULL,
                is_universal    TINYINT(1)     NOT NULL DEFAULT 1,
                reputation      INT UNSIGNED   NOT NULL DEFAULT 50,
                commission_rate DECIMAL(5, 2)  NOT NULL DEFAULT '10.00',
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE player (
                id                      VARCHAR(36)  NOT NULL,
                academy_id              VARCHAR(36)  NOT NULL,
                agent_id                VARCHAR(36)  NULL,
                first_name              VARCHAR(100) NOT NULL,
                last_name               VARCHAR(100) NOT NULL,
                date_of_birth           DATE         NOT NULL COMMENT '(DC2Type:date_immutable)',
                nationality             VARCHAR(60)  NOT NULL,
                position                VARCHAR(10)  NOT NULL COMMENT '(DC2Type:App\\Enum\\PlayerPosition)',
                status                  VARCHAR(20)  NOT NULL DEFAULT 'active' COMMENT '(DC2Type:App\\Enum\\PlayerStatus)',
                recruitment_source      VARCHAR(30)  NOT NULL COMMENT '(DC2Type:App\\Enum\\RecruitmentSource)',
                potential               SMALLINT UNSIGNED NOT NULL,
                current_ability         SMALLINT UNSIGNED NOT NULL,
                contract_value          INT UNSIGNED NOT NULL DEFAULT 0,
                personality_confidence  SMALLINT UNSIGNED NOT NULL DEFAULT 50,
                personality_maturity    SMALLINT UNSIGNED NOT NULL DEFAULT 50,
                personality_teamwork    SMALLINT UNSIGNED NOT NULL DEFAULT 50,
                personality_leadership  SMALLINT UNSIGNED NOT NULL DEFAULT 50,
                personality_ego         SMALLINT UNSIGNED NOT NULL DEFAULT 50,
                personality_bravery     SMALLINT UNSIGNED NOT NULL DEFAULT 50,
                personality_greed       SMALLINT UNSIGNED NOT NULL DEFAULT 50,
                personality_loyalty     SMALLINT UNSIGNED NOT NULL DEFAULT 50,
                created_at              DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at              DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_player_academy (academy_id),
                INDEX idx_player_agent (agent_id),
                CONSTRAINT fk_player_academy FOREIGN KEY (academy_id) REFERENCES academy (id),
                CONSTRAINT fk_player_agent   FOREIGN KEY (agent_id)   REFERENCES agent (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE player_siblings (
                player_id        VARCHAR(36) NOT NULL,
                player_target_id VARCHAR(36) NOT NULL,
                PRIMARY KEY (player_id, player_target_id),
                INDEX idx_sibling_player  (player_id),
                INDEX idx_sibling_target  (player_target_id),
                CONSTRAINT fk_sibling_player  FOREIGN KEY (player_id)        REFERENCES player (id),
                CONSTRAINT fk_sibling_target  FOREIGN KEY (player_target_id) REFERENCES player (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE guardian (
                id                  VARCHAR(36)  NOT NULL,
                player_id           VARCHAR(36)  NOT NULL,
                first_name          VARCHAR(100) NOT NULL,
                last_name           VARCHAR(100) NOT NULL,
                contact_email       VARCHAR(180) NULL,
                demand_level        SMALLINT UNSIGNED NOT NULL DEFAULT 5,
                loyalty_to_academy  SMALLINT UNSIGNED NOT NULL DEFAULT 50,
                PRIMARY KEY (id),
                UNIQUE INDEX uq_guardian_player (player_id),
                CONSTRAINT fk_guardian_player FOREIGN KEY (player_id) REFERENCES player (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE staff (
                id               VARCHAR(36)  NOT NULL,
                academy_id       VARCHAR(36)  NOT NULL,
                first_name       VARCHAR(100) NOT NULL,
                last_name        VARCHAR(100) NOT NULL,
                role             VARCHAR(30)  NOT NULL COMMENT '(DC2Type:App\\Enum\\StaffRole)',
                coaching_ability SMALLINT UNSIGNED NOT NULL DEFAULT 50,
                scouting_range   SMALLINT UNSIGNED NOT NULL DEFAULT 50,
                weekly_salary    INT UNSIGNED NOT NULL DEFAULT 0,
                hired_at         DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_staff_academy (academy_id),
                CONSTRAINT fk_staff_academy FOREIGN KEY (academy_id) REFERENCES academy (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE transfer (
                id                    VARCHAR(36)  NOT NULL,
                player_id             VARCHAR(36)  NOT NULL,
                academy_id            VARCHAR(36)  NOT NULL,
                destination_club_name VARCHAR(100) NOT NULL,
                type                  VARCHAR(20)  NOT NULL COMMENT '(DC2Type:App\\Enum\\TransferType)',
                fee                   INT UNSIGNED NOT NULL DEFAULT 0,
                agent_commission      INT UNSIGNED NOT NULL DEFAULT 0,
                occurred_at           DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                synced_at             DATETIME     NULL     COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_transfer_player  (player_id),
                INDEX idx_transfer_academy (academy_id),
                CONSTRAINT fk_transfer_player  FOREIGN KEY (player_id)  REFERENCES player (id),
                CONSTRAINT fk_transfer_academy FOREIGN KEY (academy_id) REFERENCES academy (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE sync_record (
                id                  VARCHAR(36) NOT NULL,
                academy_id          VARCHAR(36) NOT NULL,
                client_week_number  INT UNSIGNED NOT NULL,
                client_timestamp    DATETIME    NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                server_timestamp    DATETIME    NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                payload             JSON        NOT NULL,
                is_valid            TINYINT(1)  NOT NULL DEFAULT 1,
                invalid_reason      VARCHAR(255) NULL,
                PRIMARY KEY (id),
                INDEX idx_sync_record_academy (academy_id),
                CONSTRAINT fk_sync_record_academy FOREIGN KEY (academy_id) REFERENCES academy (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE leaderboard_entry (
                id         VARCHAR(36) NOT NULL,
                academy_id VARCHAR(36) NOT NULL,
                category   VARCHAR(30) NOT NULL COMMENT '(DC2Type:App\\Enum\\LeaderboardCategory)',
                score      BIGINT UNSIGNED NOT NULL DEFAULT 0,
                period     VARCHAR(20) NOT NULL,
                `rank`     INT UNSIGNED NULL,
                updated_at DATETIME    NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                UNIQUE INDEX uq_leaderboard_academy_category_period (academy_id, category, period),
                INDEX idx_leaderboard_score (category, period, score),
                CONSTRAINT fk_leaderboard_academy FOREIGN KEY (academy_id) REFERENCES academy (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
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
