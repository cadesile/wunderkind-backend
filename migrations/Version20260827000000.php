<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes for the admin dashboard's time-windowed aggregates.
 *
 * The dashboard counts users, clubs and syncs over rolling 24h/7d/30d windows
 * and builds a 30-day daily series. None of those columns were indexed, so
 * every panel load was a sequential scan of the whole table — fine when these
 * tables held a handful of rows, not once real traffic arrived.
 *
 * The composite on (club_id, server_timestamp) additionally serves the
 * "distinct clubs active in the last N" query used as the DAU/WAU proxy.
 */
final class Version20260827000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index created_at / server_timestamp columns read by the admin dashboard aggregates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_sync_record_server_timestamp ON sync_record (server_timestamp)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_sync_record_club_server_ts ON sync_record (club_id, server_timestamp)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_user_created_at ON "user" (created_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_club_created_at ON club (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_sync_record_server_timestamp');
        $this->addSql('DROP INDEX IF EXISTS idx_sync_record_club_server_ts');
        $this->addSql('DROP INDEX IF EXISTS idx_user_created_at');
        $this->addSql('DROP INDEX IF EXISTS idx_club_created_at');
    }
}
