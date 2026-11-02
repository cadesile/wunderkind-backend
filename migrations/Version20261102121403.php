<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `starter_config.setup_tutorial_config` — the optional first-run guidance block the
 * app's new-game SETUP flow reads from `GET /api/starter-config` as `tutorial`.
 *
 * Seeded with the 5 briefings and 10 notes the client already ships as its fixture
 * (`wunderkind-app/src/components/new-game-setup/__fixtures__/tutorial.mock.json`), so the
 * feature arrives on rather than dark. The app has carried the reader since 2026-11-02 and
 * all 34 `ngs.tut.*` copy keys are in its bundled catalogue, so this needs no app release
 * and no translation rows to render in English.
 *
 * **NOT NULL with a DB-level DEFAULT, deliberately.** `starter_config` always has exactly
 * one row (id = 1), so `ADD ... JSON NOT NULL` with no default fails outright on any
 * existing database — "column contains null values". The default is what populates that
 * row. Same shape as `Version20260509133608`'s `fan_base_ranges` and
 * `Version20260416205447`'s `npc_squad_config`.
 *
 * **Hand-trimmed from `migrations:diff`, and here is what was dropped and why.** The
 * generated version also carried a DROP INDEX + CREATE UNIQUE INDEX pair for
 * `uq_world_pack_generation_run_one_active_per_country` — unrelated to this change, and a
 * no-op: the two spellings differ only in how the partial predicate's casts are formatted,
 * which is the DBAL 4 `pg_get_expr` round-trip that `dbe3644` ("Clear the ORM/database
 * schema drift") documents. Per that commit's rule a noisy diff must be understood before
 * it is trimmed, not trimmed on sight; this one was, and re-running `diff` after applying
 * this migration emits nothing for `starter_config`.
 */
final class Version20261102121403 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add starter_config.setup_tutorial_config, seeded with the setup tutorial the client already ships.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE starter_config ADD setup_tutorial_config JSON NOT NULL DEFAULT '{\"version\":1,\"items\":[{\"id\":\"brief-owner\",\"kind\":\"briefing\",\"anchor\":{\"before\":\"avatar\"},\"modes\":[\"first\"],\"titleKey\":\"ngs.tut.owner.title\",\"bodyKey\":\"ngs.tut.owner.body\",\"pointKeys\":[\"ngs.tut.owner.p1\",\"ngs.tut.owner.p2\"],\"ctaKey\":\"ngs.tut.cta.gotIt\"},{\"id\":\"brief-club\",\"kind\":\"briefing\",\"anchor\":{\"before\":\"country\"},\"modes\":[\"first\",\"newClub\"],\"titleKey\":\"ngs.tut.club.title\",\"bodyKey\":\"ngs.tut.club.body\",\"pointKeys\":[\"ngs.tut.club.p1\",\"ngs.tut.club.p2\"],\"ctaKey\":\"ngs.tut.cta.gotIt\"},{\"id\":\"brief-identity\",\"kind\":\"briefing\",\"anchor\":{\"before\":\"badge\"},\"modes\":[\"first\",\"newClub\"],\"titleKey\":\"ngs.tut.identity.title\",\"bodyKey\":\"ngs.tut.identity.body\",\"pointKeys\":[\"ngs.tut.identity.p1\",\"ngs.tut.identity.p2\",\"ngs.tut.identity.p3\"],\"ctaKey\":\"ngs.tut.cta.gotIt\"},{\"id\":\"brief-staff\",\"kind\":\"briefing\",\"anchor\":{\"before\":\"hire:chairman\"},\"modes\":[\"first\",\"newClub\"],\"titleKey\":\"ngs.tut.staff.title\",\"bodyKey\":\"ngs.tut.staff.body\",\"pointKeys\":[\"ngs.tut.staff.p1\",\"ngs.tut.staff.p2\",\"ngs.tut.staff.p3\",\"ngs.tut.staff.p4\"],\"ctaKey\":\"ngs.tut.cta.gotIt\"},{\"id\":\"brief-review\",\"kind\":\"briefing\",\"anchor\":{\"before\":\"overview\"},\"modes\":[\"first\",\"newClub\"],\"titleKey\":\"ngs.tut.review.title\",\"bodyKey\":\"ngs.tut.review.body\",\"pointKeys\":[\"ngs.tut.review.p1\",\"ngs.tut.review.p2\"],\"ctaKey\":\"ngs.tut.cta.gotIt\"},{\"id\":\"note-avatar\",\"kind\":\"note\",\"anchor\":{\"on\":\"avatar\"},\"modes\":[\"first\"],\"bodyKey\":\"ngs.tut.note.avatar\",\"dismissible\":true},{\"id\":\"note-owner\",\"kind\":\"note\",\"anchor\":{\"on\":\"owner\"},\"modes\":[\"first\"],\"bodyKey\":\"ngs.tut.note.owner\",\"dismissible\":true},{\"id\":\"note-country\",\"kind\":\"note\",\"anchor\":{\"on\":\"country\"},\"bodyKey\":\"ngs.tut.note.country\",\"dismissible\":true},{\"id\":\"note-club\",\"kind\":\"note\",\"anchor\":{\"on\":\"club\"},\"bodyKey\":\"ngs.tut.note.club\",\"dismissible\":true},{\"id\":\"note-badge\",\"kind\":\"note\",\"anchor\":{\"on\":\"badge\"},\"bodyKey\":\"ngs.tut.note.badge\",\"dismissible\":true},{\"id\":\"note-kits\",\"kind\":\"note\",\"anchor\":{\"on\":\"kits\"},\"bodyKey\":\"ngs.tut.note.kits\",\"dismissible\":true},{\"id\":\"note-stadium\",\"kind\":\"note\",\"anchor\":{\"on\":\"stadium\"},\"bodyKey\":\"ngs.tut.note.stadium\",\"dismissible\":true},{\"id\":\"note-hire\",\"kind\":\"note\",\"anchor\":{\"on\":\"hire\"},\"bodyKey\":\"ngs.tut.note.hire\",\"dismissible\":true},{\"id\":\"note-role\",\"kind\":\"note\",\"anchor\":{\"on\":\"role\"},\"bodyKey\":\"ngs.tut.note.role\",\"dismissible\":true},{\"id\":\"note-overview\",\"kind\":\"note\",\"anchor\":{\"on\":\"overview\"},\"bodyKey\":\"ngs.tut.note.overview\",\"dismissible\":true}]}'");

        // Then drop it. The default exists ONLY to populate the single existing row during
        // the ALTER above — every other JSON column on this table is NOT NULL with no DB
        // default (checked: default_facilities, enabled_countries, fan_base_ranges,
        // league_ability_ranges, npc_squad_config), because the PHP property default is what
        // supplies the value for a new row. Leaving the DB default in place is drift: the
        // mapping does not declare it, so the next `migrations:diff` emits
        // `ALTER ... DROP DEFAULT` — which is exactly what the first draft of this migration
        // did, and what `dbe3644` spent a commit clearing repo-wide.
        $this->addSql('ALTER TABLE starter_config ALTER setup_tutorial_config DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE starter_config DROP setup_tutorial_config');
    }
}
