<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop `game_config.league_player_ability_ranges` — the ability table nothing read.
 *
 * ── WHY IT IS GOING RATHER THAN BEING LEFT ALONE ──────────────────────────────────────────
 *
 * There were two ability tables with nearly the same name. `starter_config.league_ability_ranges`
 * priced and generated every player. This one was published to the app and had **no
 * server-side consumer at all** — no generator, no wage code, no admin preview, and no admin
 * form either, so an operator could not even see it to keep it in step.
 *
 * They disagreed at every England tier: 55-95 against 40-90 at tier 1, 5-15 against 2-7 at tier
 * 8. The app had nine readers keyed off the published copy for NPC recruiting, squad thinning,
 * culling and contract renewal, so squads were generated from one table and judged against the
 * other. Its economy release gate was built on this table and had to withdraw its verdict.
 *
 * `Version20261113...` (the commit before this one) re-pointed the published key at the
 * authored table. Leaving the column behind would leave a second place that LOOKS authoritative
 * — a JSON column on game_config, exported and re-imported with every config backup — and the
 * next person to find it would reasonably assume editing it does something. It does not. One
 * table, one place to author it.
 *
 * ── THE PUBLISHED KEY IS NOT AFFECTED ─────────────────────────────────────────────────────
 *
 * `leaguePlayerAbilityRanges` is still served on `GET /api/game-config` and `POST /api/sync`,
 * same name, same shape, now built by `EconomyConfigPublisher::leaguePlayerAbilityRanges()`
 * from `starter_config.league_ability_ranges`. No client sees a key disappear. This is a
 * storage change only.
 *
 * ── OLD CONFIG EXPORTS STAY IMPORTABLE ────────────────────────────────────────────────────
 *
 * `ConfigImportExportService::applyEntity()` iterates the ENTITY's exportable properties and
 * skips any the payload does not carry — it never walks the payload's keys. So a backup taken
 * before today still imports cleanly; the stale `leaguePlayerAbilityRanges` entry in it is
 * simply ignored. That matters because the admin tells operators old exports are safe to
 * re-import.
 *
 * ── WHAT IS LOST ──────────────────────────────────────────────────────────────────────────
 *
 * The stored rows, which on the deployed box were eight countries' worth of bands that
 * disagreed with the ones the world is built from. Nothing reads them and nothing can be
 * derived from them, so they are not backed up here. `down()` recreates the column EMPTY —
 * the structure returns, the data does not, which is the honest limit of a reversal.
 */
final class Version20261113160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop game_config.league_player_ability_ranges; the published key of that name is now served from starter_config.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS league_player_ability_ranges');
    }

    public function down(Schema $schema): void
    {
        // Empty, not restored: see the class docblock.
        $this->addSql("ALTER TABLE game_config ADD COLUMN league_player_ability_ranges JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE game_config ALTER league_player_ability_ranges DROP DEFAULT');
    }
}
