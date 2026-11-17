<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\UuidV7;

/**
 * World pack SETS: a country's generated world and its starter squad become one unit,
 * held two-deep per country and claimable by exactly one club.
 *
 * ── What was wrong ───────────────────────────────────────────────────────────────────────
 *
 * `country_world_pack_cache` was keyed `(country, tier)` with no owner, so every player in
 * a country was served the same byte-identical world forever. The starter squad was a
 * separate, live draw at `POST /api/initialize/starter` that could fail with
 * `412 InsufficientStarterPoolException` against a country whose packs were perfectly warm —
 * a gap between "the world exists" and "a squad to play it with exists", with no client-side
 * escape at the very first step of game init.
 *
 * ── The new shape ────────────────────────────────────────────────────────────────────────
 *
 * `world_pack_set` is one generation of one country: a starter squad plus a
 * `country_world_pack_cache` row per league tier, FK'd to it. The uniqueness key on the
 * cache moves from `(country, tier)` to `(set_id, tier)` — a country now holds a buffer of
 * sets, so `(country, tier)` is no longer unique and `findOneBy(['country','tier'])` would
 * return an arbitrary one of them. Nothing may reach a cache row through its denormalised
 * country any more; `country`/`tier` stay on the row only because the admin summaries and the
 * dashboard's stale count read them directly in SQL.
 *
 * `club.world_pack_set_id` records which set a club was served, so a retried
 * `POST /api/initialize/league/{tier}` replays from the same world. That is load-bearing: the
 * client treats an empty or truncated 2xx as a retryable transport failure, and a device's
 * tiers must never be mixed across two generation runs.
 *
 * ── Three decisions worth knowing before changing any of this ────────────────────────────
 *
 * 1. **`set_id` is on `world_pack_generation_tier_run`, not on the run.** One admin
 *    regenerate builds a country's whole buffer, so a run covers `set_count` sets and its
 *    tier runs carry the set each belongs to. That keeps `world_pack_generation_run` the
 *    per-COUNTRY unit it has always been, so its single-flight partial unique index
 *    (`uq_world_pack_generation_run_one_active_per_country`) is deliberately left untouched.
 *    The tier run's own key widens to `(run_id, set_id, tier)`, plus a partial companion for
 *    the set-less rows, and its FK is CASCADE rather than SET NULL — see the FK's own comment
 *    below, because the two choices are load-bearing on each other.
 *
 * 2. **The backfilled sets are not claimable.** `postUp()` wraps each country's existing
 *    cache rows in one set so `set_id` can become NOT NULL without discarding a single warm
 *    payload — but those sets have `starter_pack_json IS NULL`, and the claim predicate
 *    excludes exactly that. Claiming one would hand a club a world with no squad and consume
 *    a slot in the buffer. They are inert stock the first regenerate per country clears, and
 *    that is what makes this deploy a no-op for every pack already warm: nothing is deleted,
 *    nothing starts failing, and the live draw keeps serving until real sets exist.
 *
 * 3. **`uq_country_tier` is a table CONSTRAINT, not an index.** It was created as
 *    `CONSTRAINT uq_country_tier UNIQUE (country, tier)` in Version20260512000001, so
 *    `DROP INDEX` fails on it and it needs `ALTER TABLE ... DROP CONSTRAINT`.
 *
 * ── And one trap, because this repo has already been bitten by it ────────────────────────
 *
 * **The backfill mints UUIDs in PHP, never with `gen_random_uuid()`.** Every id in this
 * schema is typed `UuidV7`; `gen_random_uuid()` returns a v4, which Doctrine's UuidType
 * hydrates into a `UuidV4` instance that then cannot be assigned to a `UuidV7` property —
 * a TypeError on read, long after the migration passed. Version20261027230000 backfills
 * `competition_entrant_squad.id` that way and is a latent instance of it.
 *
 * ── down() IS NOT LOSSLESS, AND CANNOT BE ─────────────────────────────────────────────────
 *
 * up() destroys nothing, but the old world cannot hold the new one. `uq_country_tier` means
 * one payload per (country, tier), and the entire point of this migration is that a country
 * holds SEVERAL — so restoring that constraint is impossible while the surplus exists. down()
 * therefore keeps each country's OLDEST set and deletes the rest (their payload rows follow
 * via ON DELETE CASCADE), which is not a side effect of the rollback but the substance of it:
 * going back to the old schema IS going back to one world per country.
 *
 * It keeps the oldest rather than the newest so that a country whose only remaining set is
 * CLAIMED does not have a device's world pulled out from under it, and so the row the old
 * `findOneBy(['country','tier'])` would have picked is the one that survives.
 */
final class Version20261117120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'World pack sets: world pack + starter squad as one claimable unit, two per country.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE world_pack_set (
                id UUID NOT NULL,
                country CHAR(2) NOT NULL,
                payload_version SMALLINT DEFAULT 0 NOT NULL,
                status VARCHAR(20) NOT NULL,
                starter_pack_json JSON DEFAULT NULL,
                tier_count SMALLINT DEFAULT 0 NOT NULL,
                error_message TEXT DEFAULT NULL,
                generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                ready_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                claimed_by_club_id UUID DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_world_pack_set_country_status ON world_pack_set (country, status)');
        // Serves the claim's WHERE and its ORDER BY in one index.
        $this->addSql('CREATE INDEX idx_world_pack_set_claimable ON world_pack_set (country, status, payload_version, generated_at)');
        // One club can never hold two sets. PARTIAL, because every unclaimed row has NULL here
        // and a plain unique index would admit only one of them.
        $this->addSql('CREATE UNIQUE INDEX uq_world_pack_set_one_per_club ON world_pack_set (claimed_by_club_id) WHERE (claimed_by_club_id IS NOT NULL)');
        $this->addSql('ALTER TABLE world_pack_set ADD CONSTRAINT fk_wps_claimed_club FOREIGN KEY (claimed_by_club_id) REFERENCES club (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Nullable here; postUp() backfills and then tightens to NOT NULL.
        $this->addSql('ALTER TABLE country_world_pack_cache ADD set_id UUID DEFAULT NULL');

        $this->addSql('ALTER TABLE world_pack_generation_run ADD set_count SMALLINT DEFAULT 1 NOT NULL');

        $this->addSql('ALTER TABLE world_pack_generation_tier_run ADD set_id UUID DEFAULT NULL');
        // CASCADE, not SET NULL. SET NULL is incompatible with the partial index created in
        // postUp(): one run covers several sets, so deleting a country's stock would null the
        // set_id of several tier runs sharing a (run_id, tier) and they would all collide,
        // making an ordinary admin "delete this country's sets" fail with a unique violation.
        $this->addSql('ALTER TABLE world_pack_generation_tier_run ADD CONSTRAINT fk_wpgtr_set FOREIGN KEY (set_id) REFERENCES world_pack_set (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Both default to the behaviour the code wants from day one, and the existing singleton
        // row picks them up from the defaults — no backfill needed.
        $this->addSql('ALTER TABLE starter_config ADD world_pack_set_target INT DEFAULT 2 NOT NULL');
        $this->addSql('ALTER TABLE starter_config ADD world_pack_claimed_retention_days INT DEFAULT 7 NOT NULL');

        $this->addSql('ALTER TABLE club ADD world_pack_set_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE club ADD CONSTRAINT fk_club_world_pack_set FOREIGN KEY (world_pack_set_id) REFERENCES world_pack_set (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_club_world_pack_set ON club (world_pack_set_id)');
    }

    public function postUp(Schema $schema): void
    {
        // One set per country that currently has cached tiers, wrapping what is already there.
        //
        // MIN(payload_version), deliberately not MAX: a country whose tiers are mixed-version
        // must present as stale so the existing version-miss path still treats it as needing a
        // rebuild. Taking the max would make a country look current on the strength of its
        // newest tier.
        $countries = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT country,
                   MIN(payload_version) AS payload_version,
                   MIN(generated_at)    AS generated_at,
                   COUNT(*)             AS tier_count
              FROM country_world_pack_cache
             GROUP BY country
        SQL);

        foreach ($countries as $row) {
            // PHP-side v7, never gen_random_uuid() — see the class docblock.
            $setId = (new UuidV7())->toRfc4122();

            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO world_pack_set
                    (id, country, payload_version, status, starter_pack_json,
                     tier_count, generated_at)
                VALUES
                    (:id, :country, :payloadVersion, 'ready', NULL, :tierCount, :generatedAt)
                SQL,
                [
                    'id'             => $setId,
                    'country'        => $row['country'],
                    'payloadVersion' => (int) $row['payload_version'],
                    'tierCount'      => (int) $row['tier_count'],
                    'generatedAt'    => $row['generated_at'],
                ],
            );

            $this->connection->executeStatement(
                'UPDATE country_world_pack_cache SET set_id = :setId WHERE country = :country',
                ['setId' => $setId, 'country' => $row['country']],
            );
        }

        $this->connection->executeStatement('ALTER TABLE country_world_pack_cache ALTER COLUMN set_id SET NOT NULL');
        // CASCADE here, unlike the tier run's SET NULL: the set OWNS its payloads, so deleting
        // one must reap them or they become rows nothing can serve, reach or clean up.
        $this->connection->executeStatement('ALTER TABLE country_world_pack_cache ADD CONSTRAINT fk_cwpc_set FOREIGN KEY (set_id) REFERENCES world_pack_set (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // A CONSTRAINT, not an index — see the class docblock.
        $this->connection->executeStatement('ALTER TABLE country_world_pack_cache DROP CONSTRAINT uq_country_tier');
        $this->connection->executeStatement('CREATE UNIQUE INDEX uq_world_pack_set_tier ON country_world_pack_cache (set_id, tier)');
        // (country, tier) stays indexed, non-uniquely: the admin summaries still group on it.
        $this->connection->executeStatement('CREATE INDEX idx_country_world_pack_cache_country_tier ON country_world_pack_cache (country, tier)');

        // Widen the tier run's key to include the set. Postgres treats NULLs as distinct, so
        // the legacy rows (set_id NULL) never conflict with each other, and every new row has
        // a set, so the live guarantee is unchanged.
        $this->connection->executeStatement('DROP INDEX uq_world_pack_generation_tier_run_run_tier');
        $this->connection->executeStatement('CREATE UNIQUE INDEX uq_world_pack_generation_tier_run_run_set_tier ON world_pack_generation_tier_run (run_id, set_id, tier)');
        // AND a partial companion, because the index above does not constrain set-less rows:
        // Postgres treats NULLs as DISTINCT, so (run, NULL, 3) twice is two different keys.
        // Without this, widening the key silently DROPPED the one-tier-run-per-(run, tier)
        // guarantee for every row with no set.
        $this->connection->executeStatement('CREATE UNIQUE INDEX uq_world_pack_generation_tier_run_run_tier_no_set ON world_pack_generation_tier_run (run_id, tier) WHERE (set_id IS NULL)');
    }

    public function down(Schema $schema): void
    {
        // Reduce every country to one set BEFORE uq_country_tier goes back on, or the index
        // cannot be created. See the class docblock: this is the rollback, not a shortcut.
        $this->addSql(<<<'SQL'
            DELETE FROM world_pack_set
             WHERE id NOT IN (
                     SELECT DISTINCT ON (country) id
                       FROM world_pack_set
                      ORDER BY country, generated_at ASC
                   )
            SQL);

        $this->addSql('DROP INDEX uq_world_pack_generation_tier_run_run_tier_no_set');
        $this->addSql('DROP INDEX uq_world_pack_generation_tier_run_run_set_tier');
        $this->addSql('CREATE UNIQUE INDEX uq_world_pack_generation_tier_run_run_tier ON world_pack_generation_tier_run (run_id, tier)');
        $this->addSql('ALTER TABLE world_pack_generation_tier_run DROP CONSTRAINT fk_wpgtr_set');
        $this->addSql('ALTER TABLE world_pack_generation_tier_run DROP set_id');

        $this->addSql('ALTER TABLE world_pack_generation_run DROP set_count');

        $this->addSql('ALTER TABLE starter_config DROP world_pack_claimed_retention_days');
        $this->addSql('ALTER TABLE starter_config DROP world_pack_set_target');

        $this->addSql('ALTER TABLE club DROP CONSTRAINT fk_club_world_pack_set');
        $this->addSql('DROP INDEX idx_club_world_pack_set');
        $this->addSql('ALTER TABLE club DROP world_pack_set_id');

        $this->addSql('DROP INDEX idx_country_world_pack_cache_country_tier');
        $this->addSql('DROP INDEX uq_world_pack_set_tier');
        $this->addSql('ALTER TABLE country_world_pack_cache DROP CONSTRAINT fk_cwpc_set');
        $this->addSql('ALTER TABLE country_world_pack_cache DROP set_id');
        // Safe to restore: no two rows could have shared (country, tier) while the constraint
        // was absent, because every write in that window went through a set.
        $this->addSql('ALTER TABLE country_world_pack_cache ADD CONSTRAINT uq_country_tier UNIQUE (country, tier)');

        $this->addSql('DROP TABLE world_pack_set');
    }
}
