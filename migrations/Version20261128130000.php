<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sync idempotency (Courtney, 2026-11-28): a resent sync payload must be replayed, not
 * applied twice. Existing rows keep a NULL key, and Postgres treats NULLs as distinct, so
 * the duplicates already on record do not block the index.
 */
final class Version20261128130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sync_record.idempotency_key + unique (club_id, idempotency_key)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sync_record ADD idempotency_key VARCHAR(120) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uq_sync_record_club_idempotency_key ON sync_record (club_id, idempotency_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uq_sync_record_club_idempotency_key');
        $this->addSql('ALTER TABLE sync_record DROP idempotency_key');
    }
}
