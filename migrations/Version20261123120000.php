<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A served world-pack tier is stamped, not deleted, so a retry can never mix worlds.
 *
 * Owner, 2026-11-23: "we should never get mixed worlds." Since df3cae1 the tier endpoint
 * deleted each row as it served it, so a retried call (a lost response, a restarted load) found
 * nothing and built a fresh tier from another generation. `country_world_pack_cache.served_at`
 * records the first hand-over instead; the row stays until `app:worldpack:maintain` clears worlds
 * that finished loading more than an hour ago.
 *
 * Additive and nullable: existing rows read as not yet served, which is true for unclaimed
 * stock and harmless for a claimed set (its next serve stamps it). `down()` drops the column.
 */
final class Version20261123120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add country_world_pack_cache.served_at so served tiers are kept for replay rather than deleted.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country_world_pack_cache ADD served_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE country_world_pack_cache DROP served_at');
    }
}
