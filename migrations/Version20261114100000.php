<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-tier sponsor bands and ticket prices, both shipping unauthored.
 *
 * ── WHY PER TIER ──────────────────────────────────────────────────────────────────────────
 *
 * The six flat `*_sponsor_min/max` columns are one band for every division, scaled only by the
 * country factor — so a tier-8 club and a tier-1 club in the same country draw sponsorship from
 * an identical pot, separated only by how many sponsors their league has. Tolerable while the
 * economy was flat; against a turnover ladder spanning 2,357x it is not.
 *
 * Ticket price has no predecessor at all: the backend has never held one. It exists now so the
 * price per division is an operator figure rather than a constant compiled into an app release
 * — the same reasoning as `matchday_food_uptake_percent` and `npc_solvency_thresholds`.
 *
 * ── EIGHT AUTHORED ROWS, NOT A FACTOR, BY DECISION ────────────────────────────────────────
 *
 * Deliberately not `baseBand x economy_tier_factors_bp`, which would be one figure instead of
 * eight. The owner chose direct per-tier control, and the reason stands on its own: sponsorship
 * and gate receipts are separate revenue lines an operator tunes against TV and matchday, not
 * derivatives of the wage ladder. Deriving them from the tier factors would mean a wage retune
 * silently re-priced every sponsor and every ticket in the world.
 *
 * The accepted cost is that nothing forces the eight rows to be monotonic. The gate reports a
 * non-monotonic ladder rather than the setter forbidding it, because a division that pays
 * better than the one above it may be deliberate.
 *
 * ── BOTH SHIP INERT ───────────────────────────────────────────────────────────────────────
 *
 * `economy_sponsor_bands_per_tier_pence` defaults to `[]`: every tier falls through to the flat
 * columns, so sponsorship is byte-identical to today on every box. A tier is authored all three
 * sizes at once or not at all — a partial row would fall back for one size and not the others,
 * which is the hardest kind of band to debug.
 *
 * `economy_ticket_price_per_tier_pence` defaults to eight zeros, and 0 means "unset, use your
 * own figure" per tier rather than "free". There is no flat key to fall back to, which is
 * exactly why the zero has to carry that meaning — see the column's docblock.
 *
 * Add-with-default then DROP DEFAULT on both, the pattern this series uses for JSON columns so
 * the mapping owns the default afterwards rather than the database.
 *
 * Reversible: two drops.
 */
final class Version20261114100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-tier sponsor bands and ticket prices to game_config, both unauthored so behaviour is unchanged.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_config ADD economy_sponsor_bands_per_tier_pence JSON DEFAULT '[]' NOT NULL");
        $this->addSql("ALTER TABLE game_config ADD economy_ticket_price_per_tier_pence JSON DEFAULT '[0,0,0,0,0,0,0,0]' NOT NULL");
        $this->addSql('ALTER TABLE game_config ALTER economy_sponsor_bands_per_tier_pence DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER economy_ticket_price_per_tier_pence DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP economy_sponsor_bands_per_tier_pence');
        $this->addSql('ALTER TABLE game_config DROP economy_ticket_price_per_tier_pence');
    }
}
