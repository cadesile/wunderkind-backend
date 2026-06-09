<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260608000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create beta_request table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE beta_request (
                id          UUID                      NOT NULL PRIMARY KEY,
                email       VARCHAR(180)              NOT NULL,
                code        VARCHAR(6)                NOT NULL,
                valid       BOOLEAN                   NOT NULL DEFAULT FALSE,
                attempts    INTEGER                   NOT NULL DEFAULT 0,
                expires_at  TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                created_at  TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL
            )
        ');

        $this->addSql('CREATE INDEX idx_beta_request_email ON beta_request (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE beta_request');
    }
}
