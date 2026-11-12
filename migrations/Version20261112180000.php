<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A league's TV money becomes a POOL, with the per-club share kept as the published figure.
 *
 * `league.tv_deal` is a key shipped builds read and treat as ONE CLUB'S money. Re-interpreting
 * it as a pool would be a changed value under an unchanged key, which
 * .context/shared/api-backwards-compatibility.md counts as a break — so the pool is a new
 * nullable column and `tv_deal` is maintained from it.
 *
 * ── WHY THE BACKFILL MAKES THIS A PROVABLE NO-OP ──────────────────────────────────────────
 *
 * `tv_pool_pence = tv_deal * sizeOf(league)` means dividing the pool back by the same size
 * reproduces `tv_deal` exactly, by construction. So every league comes out of this migration
 * publishing the figure it published going in, whatever the divisor turns out to be — which is
 * what lets the size question be settled on its merits rather than under deploy pressure.
 *
 * Leagues with `tv_deal IS NULL` get a NULL pool, not zero. "No TV money" and "a TV deal of
 * nothing" are different configurations and LeagueTierDefaultDiff compares them strictly.
 *
 * ── `slots`, AND WHY A SIZE IS AUTHORED RATHER THAN COUNTED ────────────────────────────────
 *
 * A `League` row is a TEMPLATE shared by every save in its country and tier: on 2026-11-12
 * England tier 8 had 147 real player clubs pointing at one row. The eighteen-club table a
 * player sees is assembled on their device. So nothing countable on the server means what a
 * divisor needs to mean, and the obvious derivation — NPC rows plus one for the bottom tier —
 * is right for 9 of 15 bootstrapped countries and wrong for 6 (CA, JP, NG, PL, US and SC sit
 * at a uniform count, so the bottom tier would come out one LARGER than the rest).
 *
 * The decisive objection is not the off-by-one: a derived divisor MOVES whenever somebody
 * clicks Generate, silently re-pricing a tier's TV money for every shipped build as a side
 * effect of an unrelated admin action. So `slots` is authored, **0 means "derive"** — the same
 * zero-means-unset idiom as starter_config.club_funding_minimum_pence — and the derivation
 * stays as the fallback, so an operator who prefers the pure rule simply never sets it.
 *
 * postUp() authors `slots` from the MAXIMUM NPC count across each country's tiers, which is
 * the playing size for the nine properly-bootstrapped countries (EN/DE/IT 18, the six at 16)
 * and makes it uniform per country rather than one-short at the bottom.
 *
 * ── `league_position_decrease_percent` ────────────────────────────────────────────────────
 *
 * Nullable, because "not overridden" has to be expressible and `0` cannot be it — a taper of
 * zero means every finishing position pays the same, which is a legitimate setting. Resolved
 * `league ?? gameConfig` at every publish site, so the key keeps its meaning and its value.
 *
 * Reversible: three plain drops.
 */
final class Version20261112180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add league.tv_pool_pence, slots and a per-league position taper; backfill the pool so every published tv_deal is unchanged.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE league ADD tv_pool_pence BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE league ADD slots SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE league ADD league_position_decrease_percent SMALLINT DEFAULT NULL');
    }

    public function postUp(Schema $schema): void
    {
        // Author the size first: the pool backfill below divides by it, and the two have to
        // agree or the round trip stops being exact.
        //
        // MAX across the country's tiers, not the per-tier count. The bottom tier of a
        // properly-bootstrapped country is one short because that slot belongs to the player's
        // club, so the maximum is the playing size and using it makes `slots` uniform per
        // country. A country with no NPC clubs at all keeps 0, which means "derive".
        $this->connection->executeStatement(<<<'SQL'
            UPDATE league l
               SET slots = sub.max_clubs
              FROM (
                    SELECT c.country, MAX(c.cnt) AS max_clubs
                      FROM (
                            SELECT n.country, n.league_id, COUNT(*) AS cnt
                              FROM npc_club n
                             WHERE n.league_id IS NOT NULL
                          GROUP BY n.country, n.league_id
                           ) c
                  GROUP BY c.country
                   ) sub
             WHERE sub.country = l.country
               AND sub.max_clubs > 0
        SQL);

        // Then the pool, from the size just authored — so pool / slots === tv_deal exactly.
        // Falls back to the per-league NPC count (plus the bottom-tier slot) for a country that
        // got no `slots`, which is the same rule LeagueSizeResolver uses, so the two cannot
        // disagree about what this row was divided by.
        $this->connection->executeStatement(<<<'SQL'
            UPDATE league l
               SET tv_pool_pence = l.tv_deal * GREATEST(
                     CASE WHEN l.slots > 0 THEN l.slots ELSE
                          (SELECT COUNT(*) FROM npc_club n WHERE n.league_id = l.id)
                          + CASE WHEN l.tier = (SELECT MAX(m.tier) FROM league m WHERE m.country = l.country)
                                 THEN 1 ELSE 0 END
                     END, 1)
             WHERE l.tv_deal IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE league DROP league_position_decrease_percent');
        $this->addSql('ALTER TABLE league DROP slots');
        $this->addSql('ALTER TABLE league DROP tv_pool_pence');
    }
}
