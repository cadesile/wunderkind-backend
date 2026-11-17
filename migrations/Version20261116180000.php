<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The champion's prize and the position pot as a tier-1 base plus a ratio table. Ships inert.
 *
 * ── WHAT IS WRONG WITH THE FIGURES THIS REPLACES ──────────────────────────────────────────
 *
 * `league.prize_money` and `league.league_position_pot` are PER-CLUB figures that read like
 * pools, and the authored England ladder was sized as though they were pools. Measured against
 * the turnover table authored on dev 2026-11-13, it INVERTS at tier 3:
 *
 *   tier:            1       2       3       4       5       6        7        8
 *   prize/turnover   0.26x   0.62x   1.41x   1.53x   3.61x   6.41x   17.16x   30.33x
 *   pot/turnover     0.26x   0.67x   1.41x   1.51x   1.41x   3.72x    8.58x   12.13x
 *
 * while `tv_deal` runs monotone the other way, 0.350x down to 0.030x. A tier-8 champion is paid
 * £5,000,000 against £164,852 of authored annual turnover, so a bottom-tier club has more
 * financial reason to win its division than to be promoted out of it, and the division as a
 * whole pays out 5.42x its combined authored turnover across 18 clubs.
 *
 * The same ladder was cascaded byte-identically to all 24 eight-tier countries, and SC holds
 * rows 1-4 of it, so nothing about these figures was ever country-specific either.
 *
 * ── WHY A RATIO TABLE ─────────────────────────────────────────────────────────────────────
 *
 * The same argument `Version20261115190000` makes for sponsors and tickets. Authoring these two
 * ladders as absolutes means deriving and typing 16 figures per country by hand, and deriving
 * all 16 again the next time the tier-1 figure moves. As a base plus eight ratios it is nine
 * figures per ladder and a retune of the base carries the ladder with it.
 *
 * ── PARTS PER MILLION, NOT BASIS POINTS ───────────────────────────────────────────────────
 *
 * The unit is the one thing here that could not be copied from the sponsor work. This ladder is
 * 23x steeper: tier 8 over tier 1 is 424 ppm, which is 4.24 BASIS POINTS. An integer bp table
 * would store 4 — a 5.7% error — and, worse, ONE BP STEP WOULD BE 23.6% OF THE VALUE, so the
 * bottom of the ladder could not be adjusted at all. ppm gives a 0.236% step and holds every
 * row inside 0.036% of its target. The authored sponsor ratios bottom out at 96 bp, where bp is
 * still fine, which is why that table is not being changed.
 *
 * ── FOUR COLUMNS, ALL INERT ───────────────────────────────────────────────────────────────
 *
 * Two bases at 0 and two ratio tables at parity. 0 propagates through `LeaguePayoutResolver`
 * whatever the ratios say, AND every one of the 196 leagues currently holds a non-null column,
 * which the resolver treats as an override and returns verbatim. So the resolved figure is the
 * column, for every league in the world, on both counts — this migration cannot move a
 * published value.
 *
 * The bases are held here rather than on `game_config.league_tier_defaults`'s tier-1 row for
 * the reason `economy_ticket_price_tier1_pence` is held apart from
 * `economy_ticket_price_per_tier_pence[0]`: the tier-defaults row is what the bulk-apply screen
 * WRITES ONTO LEAGUES, so reading the base out of it would mean clearing tier 1's default
 * unprices every division in the world, and authoring it would create an override on every
 * tier-1 league as a side effect.
 *
 * Add-with-default then DROP DEFAULT on all four — the pattern this series uses, so the mapping
 * owns the default afterwards rather than the database.
 *
 * ── ADDITIVE ──────────────────────────────────────────────────────────────────────────────
 *
 * Four columns added. Nothing dropped, altered or backfilled; no row of any other table is
 * touched. An older build reads exactly what it read before, because the two keys it reads
 * (`prizeMoney`, `leaguePositionPot`) keep their names, their nullable-int types and their
 * values.
 *
 * The re-pricing is a SEPARATE migration (`Version20261116181000`), which authors the ladder
 * and clears the per-league columns in one transaction and stashes what it replaced.
 *
 * Reversible: four drops. Note that authored ratios live nowhere else, so take a config export
 * (`ConfigImportExportService`) before rolling this back.
 */
final class Version20261116180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-tier prize and position-pot ratio tables (parts per million) plus their two tier-1 bases to game_config, all unauthored so no published figure moves.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_config ADD economy_prize_tier_ratio_ppm JSON DEFAULT '[1000000,1000000,1000000,1000000,1000000,1000000,1000000,1000000]' NOT NULL");
        $this->addSql("ALTER TABLE game_config ADD economy_position_pot_tier_ratio_ppm JSON DEFAULT '[1000000,1000000,1000000,1000000,1000000,1000000,1000000,1000000]' NOT NULL");
        $this->addSql('ALTER TABLE game_config ADD economy_league_prize_tier1_pence BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD economy_league_position_pot_tier1_pence BIGINT DEFAULT 0 NOT NULL');

        $this->addSql('ALTER TABLE game_config ALTER economy_prize_tier_ratio_ppm DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER economy_position_pot_tier_ratio_ppm DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER economy_league_prize_tier1_pence DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER economy_league_position_pot_tier1_pence DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP economy_prize_tier_ratio_ppm');
        $this->addSql('ALTER TABLE game_config DROP economy_position_pot_tier_ratio_ppm');
        $this->addSql('ALTER TABLE game_config DROP economy_league_prize_tier1_pence');
        $this->addSql('ALTER TABLE game_config DROP economy_league_position_pot_tier1_pence');
    }
}
