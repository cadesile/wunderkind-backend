<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260302000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin table; backfill ROLE_ACADEMY on existing game users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE admin (
                id           BINARY(16)   NOT NULL,
                user_id      BINARY(16)   NOT NULL,
                department   VARCHAR(100) NULL,
                access_level INT UNSIGNED NOT NULL DEFAULT 1,
                created_at   DATETIME     NOT NULL,
                UNIQUE INDEX UNIQ_880E0D76A76ED395 (user_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);

        $this->addSql(
            'ALTER TABLE admin ADD CONSTRAINT FK_ADMIN_USER FOREIGN KEY (user_id) REFERENCES `user` (id)'
        );

        // All users without ROLE_ADMIN are game users — assign ROLE_ACADEMY.
        // JSON_CONTAINS returns 0 for empty arrays, so [] rows are correctly updated.
        $this->addSql(
            "UPDATE `user` SET roles = '[\"ROLE_ACADEMY\"]' WHERE NOT JSON_CONTAINS(roles, '\"ROLE_ADMIN\"', '\$')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin DROP FOREIGN KEY FK_ADMIN_USER');
        $this->addSql('DROP TABLE admin');
        $this->addSql("UPDATE `user` SET roles = '[]'");
    }
}
