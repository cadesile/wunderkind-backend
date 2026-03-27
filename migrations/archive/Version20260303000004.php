<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create inbox_message table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE inbox_message (
                id                   BINARY(16)   NOT NULL,
                academy_id           BINARY(16)   NOT NULL,
                sender_type          VARCHAR(30)  NOT NULL,
                sender_name          VARCHAR(150) NOT NULL,
                subject              VARCHAR(255) NOT NULL,
                body                 LONGTEXT     NOT NULL,
                offer_data           JSON         DEFAULT NULL,
                status               VARCHAR(30)  NOT NULL DEFAULT 'unread',
                related_entity_type  VARCHAR(100) DEFAULT NULL,
                related_entity_id    VARCHAR(36)  DEFAULT NULL,
                created_at           DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                responded_at         DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_inbox_academy (academy_id),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);

        $this->addSql(
            'ALTER TABLE inbox_message ADD CONSTRAINT FK_inbox_academy FOREIGN KEY (academy_id) REFERENCES academy (id) ON DELETE CASCADE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_message DROP FOREIGN KEY FK_inbox_academy');
        $this->addSql('DROP TABLE inbox_message');
    }
}
