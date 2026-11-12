<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What a relegated club is paid on the way down, per league and globally.
 *
 * SERVED VERBATIM — A SCHEMA-AGNOSTIC PASSTHROUGH, exactly like `league.trophy`,
 * `player.appearance` and `npc_club.identity`. The backend stores this, resolves which one
 * applies, and publishes it. It does not read inside it.
 *
 * THAT IS THE DECISION WORTH RECORDING, because the obvious alternative looks better and is
 * not. The backend cannot pay a club anything: there is no income loop, the device runs the
 * economy. So any structure imposed here would be a second opinion about a schedule only the
 * app can execute, and the two would drift the first time the app wanted a fourth season or a
 * cliff instead of a taper. A schema the app owns outright cannot drift from itself.
 *
 * The accepted cost is that a malformed block is stored and served and only the app notices.
 * Same trade `starter_config.setup_tutorial_config` makes, which is why that one has a checker
 * command rather than a validator.
 *
 * ── BOTH COLUMNS ARE NULLABLE AND SHIP NULL, WHICH IS THE INERT STATE ─────────────────────
 *
 * Resolution is `league ?? game_config`, whole-value. With neither authored, every publish site
 * emits `parachute: null` — a key an old build does not read, and a new build reads as "no
 * parachute scheme configured" rather than as an empty one it might try to pay out. Nothing
 * about the deploy changes behaviour.
 *
 * Whole-value rather than merged key by key, deliberately: merging would require understanding
 * the keys, which is precisely what a verbatim passthrough declines to do, and a
 * half-overridden schedule is a worse answer than either whole one.
 *
 * JSON not JSONB, matching `league.trophy` and every other config array here, so
 * migrations:diff stays quiet. Nothing queries into it.
 *
 * NO DEFAULT TO DROP on either column, unlike the json columns in the earlier migrations of
 * this series: both are NULLABLE, so adding them needs nothing for the existing rows.
 *
 * Reversible: two plain drops.
 */
final class Version20261112200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the per-league and global parachute blocks, served verbatim and resolved league ?? gameConfig.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE league ADD parachute JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE game_config ADD league_parachute JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP league_parachute');
        $this->addSql('ALTER TABLE league DROP parachute');
    }
}
