<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `starter_config.fan_base_share_percent` — the per-tier SHARE of a club's catchment that
 * supports it, published as `fanBaseSharePercent` on `GET /api/starter-config`.
 *
 * ⚠️ **A NEW COLUMN BESIDE `fan_base_ranges`, NEVER A REINTERPRETATION OF IT.** The two are
 * 1,000x apart in unit: `fan_base_ranges` holds absolute headcounts (the live row reads
 * `{"1":{"min":700000,"max":1500000}}`), this holds whole percentages. Re-using the old column
 * for a share would have given every tier-1 club 700,000% of its catchment on the next sync.
 * `fan_base_ranges` is untouched here and stays the app's fallback.
 *
 * The seeded ladder is the authored default (2026-11-19): 60-70% at tier 1 falling geometrically
 * to 15-20% at tier 8. Deliberately shallow — NPC generation already seats higher tiers in
 * bigger places, so catchment supplies most of the pyramid gradient and the share's job is to
 * set the level, not to repeat the gradient. See `StarterConfig::$fanBaseSharePercent`.
 *
 * **NOT NULL with a DB-level DEFAULT that is then dropped**, the same shape as
 * `Version20261102121403` and `Version20260509133608`: `starter_config` always holds exactly one
 * row, so `ADD ... JSON NOT NULL` with no default fails on an existing database, and leaving the
 * default in place is mapping drift the next `migrations:diff` would emit.
 *
 * ⚠️ **Nothing changes in a running game on this migration alone.** The app only takes the share
 * path when BOTH this ladder and the club's catchment are known; the catchment comes from the
 * world pack's `populationSize`, which the pack has carried since before v11. So publishing this
 * is what switches the feature on, and it switches on at the next `GET /api/starter-config`.
 */
final class Version20261119190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add starter_config.fan_base_share_percent, seeded with the authored catchment share ladder.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE starter_config ADD fan_base_share_percent JSON NOT NULL DEFAULT '
            . '\'{"1":{"min":60,"max":70},"2":{"min":49,"max":59},"3":{"min":40,"max":49},'
            . '"4":{"min":33,"max":41},"5":{"min":27,"max":34},"6":{"min":22,"max":29},'
            . '"7":{"min":18,"max":24},"8":{"min":15,"max":20}}\''
        );

        $this->addSql('ALTER TABLE starter_config ALTER fan_base_share_percent DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE starter_config DROP fan_base_share_percent');
    }
}
