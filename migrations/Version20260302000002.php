<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260302000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dob/nationality/judgements/experience/rating to agent; create scout, investor, sponsor tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent
            ADD dob DATE DEFAULT NULL,
            ADD nationality VARCHAR(60) DEFAULT NULL,
            ADD judgements JSON NOT NULL DEFAULT (JSON_ARRAY()),
            ADD experience INT UNSIGNED NOT NULL DEFAULT 0,
            ADD rating SMALLINT UNSIGNED NOT NULL DEFAULT 50
        ');

        $this->addSql('CREATE TABLE scout (
            id BINARY(16) NOT NULL,
            name VARCHAR(100) NOT NULL,
            dob DATE DEFAULT NULL,
            nationality VARCHAR(60) DEFAULT NULL,
            judgements JSON NOT NULL,
            experience INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE investor (
            id BINARY(16) NOT NULL,
            company VARCHAR(150) NOT NULL,
            nationality VARCHAR(60) DEFAULT NULL,
            size VARCHAR(255) NOT NULL DEFAULT \'medium\',
            is_active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE sponsor (
            id BINARY(16) NOT NULL,
            company VARCHAR(150) NOT NULL,
            nationality VARCHAR(60) DEFAULT NULL,
            size VARCHAR(255) NOT NULL DEFAULT \'medium\',
            is_active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scout');
        $this->addSql('DROP TABLE investor');
        $this->addSql('DROP TABLE sponsor');

        $this->addSql('ALTER TABLE agent
            DROP COLUMN dob,
            DROP COLUMN nationality,
            DROP COLUMN judgements,
            DROP COLUMN experience,
            DROP COLUMN rating
        ');
    }
}
