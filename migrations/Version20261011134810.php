<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Clears the last of the long-standing schema drift between the ORM mapping and
 * the database — the noise that made every `doctrine:migrations:diff` on this
 * project emit ~90 unrelated statements and, worse, propose DROPping four
 * raw-SQL partial unique indexes that Doctrine could not see.
 *
 * The bulk of that drift (73 column defaults, 4 partial unique indexes, 2 index
 * renames, 1 unmapped index) was fixed in the MAPPING in the same commit, which
 * needs no DDL at all. Only three things genuinely required the database to
 * move, and they are all that is here:
 *
 *  1. Six json columns whose DB-level DEFAULT the mapping never declared.
 *     Dropping them is what Doctrine has been asking for all along. Safe: all
 *     six are written by the ORM on every insert (each has a PHP-side default),
 *     and nothing in src/ or any live migration inserts these tables with raw
 *     SQL. A json DEFAULT is near-useless anyway — it can never be read back
 *     through the mapping.
 *  2. pool_config.scout_experience_min/max — a REAL inconsistency, not noise.
 *     The entity has documented defaults of 10 and 80; the database was sitting
 *     on 0 and 10, so a freshly inserted PoolConfig row disagreed with the
 *     entity depending on how it was created. The entity is authoritative.
 *  3. deletion_request.id's leftover (DC2Type:uuid) comment, which DBAL 4 no
 *     longer emits. Cosmetic, but it is drift and it comes back on every diff.
 *
 * After this, `doctrine:schema:update --dump-sql` reports no changes.
 */
final class Version20261011134810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clear residual ORM/database schema drift: unmapped json defaults, wrong scout-experience defaults, stale DC2Type comment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE starter_config ALTER enabled_countries DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER npc_club_size_weights DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER stat_post_rotation DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER stat_post_schedule DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER stat_post_last_run_at DROP DEFAULT');
        $this->addSql('ALTER TABLE live_telemetry_snapshot ALTER recent_events DROP DEFAULT');

        $this->addSql('ALTER TABLE pool_config ALTER scout_experience_min SET DEFAULT 10');
        $this->addSql('ALTER TABLE pool_config ALTER scout_experience_max SET DEFAULT 80');

        $this->addSql("COMMENT ON COLUMN deletion_request.id IS ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE starter_config ALTER enabled_countries SET DEFAULT \'["EN"]\'');
        $this->addSql('ALTER TABLE game_config ALTER npc_club_size_weights SET DEFAULT \'{"tier1":{"big":70,"medium":25,"small":5},"tier8":{"big":5,"medium":25,"small":70}}\'');
        $this->addSql("ALTER TABLE game_config ALTER stat_post_rotation SET DEFAULT '{}'");
        $this->addSql("ALTER TABLE game_config ALTER stat_post_schedule SET DEFAULT '{}'");
        $this->addSql("ALTER TABLE game_config ALTER stat_post_last_run_at SET DEFAULT '{}'");
        $this->addSql("ALTER TABLE live_telemetry_snapshot ALTER recent_events SET DEFAULT '[]'");

        $this->addSql('ALTER TABLE pool_config ALTER scout_experience_min SET DEFAULT 0');
        $this->addSql('ALTER TABLE pool_config ALTER scout_experience_max SET DEFAULT 10');

        $this->addSql("COMMENT ON COLUMN deletion_request.id IS '(DC2Type:uuid)'");
    }
}
