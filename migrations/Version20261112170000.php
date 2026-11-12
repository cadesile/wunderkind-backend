<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Four gameplay constants the app reads and the backend only publishes.
 *
 * NONE OF THESE IS CONSUMED SERVER-SIDE, and that is the normal shape for this table rather
 * than an oversight: the device is the game engine, so matchday income, NPC insolvency and a
 * Director of Football's opinion of the wage bill all happen there. They live here so an
 * operator can retune them without an app release, which is what `/api/game-config` is for.
 *
 * ADDITIVE AND SAFE FOR AN OLD BUILD. A shipped client does not read these keys at all, so
 * publishing them changes nothing for it. `sponsor_weekly_pence` defaults to 0 — "no weekly
 * sponsor income" — so even a client that DOES read it behaves as though the mechanic is off
 * until an operator sets a figure.
 *
 * ⚠️ `sponsor_weekly_pence` IS WEEKLY, WHERE THE SIX EXISTING SPONSOR BAND COLUMNS
 * (small/medium/large min+max) ARE PER SEASON. Two sponsor money figures in one table in
 * different time units is a trap, so the unit is in the column name, the entity docblock and
 * docs/api/economy-config.md. They are not convertible by dividing by a season length — a band
 * is a league sponsor's season total rolled at pack-build time, this is a per-week payout.
 *
 * The two json columns are added WITH a default and then have it DROPPED, because game_config
 * is a populated singleton (NOT NULL needs something for the existing row) but a json column
 * keeping a database-side default is drift the mapping does not declare — the exact problem
 * Version20261011134810 had to go back and clean up for six other json columns.
 *
 * JSON not JSONB, matching every other config array here. Nothing queries into them; they are
 * read whole and walked as ordered ladders.
 */
final class Version20261112170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Publish matchday food uptake, NPC solvency thresholds, DoF wage-bill thresholds and a weekly sponsor figure.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD matchday_food_uptake_percent SMALLINT DEFAULT 35 NOT NULL');
        $this->addSql("ALTER TABLE game_config ADD npc_solvency_thresholds JSON DEFAULT '[4,12,26]' NOT NULL");
        $this->addSql('ALTER TABLE game_config ALTER npc_solvency_thresholds DROP DEFAULT');
        $this->addSql("ALTER TABLE game_config ADD dof_wage_bill_threshold_pct JSON DEFAULT '[0.4,0.5,0.6,0.7]' NOT NULL");
        $this->addSql('ALTER TABLE game_config ALTER dof_wage_bill_threshold_pct DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ADD sponsor_weekly_pence BIGINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP sponsor_weekly_pence');
        $this->addSql('ALTER TABLE game_config DROP dof_wage_bill_threshold_pct');
        $this->addSql('ALTER TABLE game_config DROP npc_solvency_thresholds');
        $this->addSql('ALTER TABLE game_config DROP matchday_food_uptake_percent');
    }
}
