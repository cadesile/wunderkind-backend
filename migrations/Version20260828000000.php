<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Web account-deletion audit trail, plus the YouTube channel used by the landing page.
 *
 * Google Play and iOS both require a browser-reachable deletion route and expect
 * evidence that requests are actioned; deletion_request is that evidence.
 */
final class Version20260828000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deletion_request audit table and game_config.youtube_channel_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE deletion_request (
                id UUID NOT NULL,
                email VARCHAR(180) NOT NULL,
                status VARCHAR(40) NOT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                failure_reason VARCHAR(255) DEFAULT NULL,
                clubs_deleted INT DEFAULT 0 NOT NULL,
                requested_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                completed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('COMMENT ON COLUMN deletion_request.id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE INDEX idx_deletion_request_email ON deletion_request (email)');
        $this->addSql('CREATE INDEX idx_deletion_request_requested_at ON deletion_request (requested_at)');

        $this->addSql("ALTER TABLE game_config ADD youtube_channel_id VARCHAR(64) DEFAULT 'UC_NapTnWVJf_XSDbiGbLMBA'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS deletion_request');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS youtube_channel_id');
    }
}
