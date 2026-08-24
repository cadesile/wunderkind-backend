<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stamp world-pack cache rows with the payload shape version they were built at.
 *
 * The cache has no TTL and no invalidation, so a row built before a snapshot
 * change is served verbatim forever. Existing rows default to 0, which never
 * matches WorldInitializationService::WORLD_PACK_VERSION, so they are treated as
 * a miss and rebuilt on first read — no manual `--force` warm required.
 */
final class Version20260824000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add payload_version to country_world_pack_cache so stale packs self-invalidate.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country_world_pack_cache ADD payload_version SMALLINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country_world_pack_cache DROP payload_version');
    }
}
