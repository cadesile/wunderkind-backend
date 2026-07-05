<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260705141806 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add social_account_connection table for OAuth-based Facebook/X account connections';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE social_account_connection (id UUID NOT NULL, platform VARCHAR(255) NOT NULL, display_name VARCHAR(255) NOT NULL, external_account_id VARCHAR(255) NOT NULL, access_token TEXT NOT NULL, refresh_token TEXT DEFAULT NULL, token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_active BOOLEAN NOT NULL, connected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_refreshed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_social_platform_external_id ON social_account_connection (platform, external_account_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE social_account_connection');
    }
}
