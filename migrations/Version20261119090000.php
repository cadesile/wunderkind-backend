<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Author `league.slots` wherever it is still 0, so the division size stops being a side effect.
 *
 * ⚠️ THIS MIGRATION IS DESIGNED TO CHANGE NOTHING ON THE DAY IT LANDS, and that is the whole
 * point of it. It follows `Version20261118120000`'s shape: everything it replaces is stashed
 * first so `down()` is exact rather than approximate.
 *
 * ── WHY ───────────────────────────────────────────────────────────────────────────────────
 *
 * `slots` ships as 0, which `LeagueSizeResolver::sizeOf()` reads as "derive" — it then returns
 * the live NPC club count, plus one for the country's bottom division where a new player club is
 * placed. So the division size, every club's matchday, the app gate's `homeGames` and the
 * per-club divisor in `app:economy:wage-table`'s check 8 all follow **however many club rows
 * happen to exist**.
 *
 * Measured 2026-11-18: a spoof-club purge took England from 20 club rows per tier to 16 and
 * thereby re-priced every division in the world — matchday fell ~20% and six tiers dropped below
 * the app's coverage floor — with no economy change authored anywhere. `LeagueSizeResolver`'s own
 * docblock already names this as the fatal objection to a derived divisor: *"a derived divisor
 * moves whenever somebody clicks Generate."* The resolver was built with the authored path first
 * and the derivation as a fallback; this migration is what finally takes the fallback out of
 * production use.
 *
 * ⚠️ **IT DOES NOT PICK A DIVISION SIZE.** 16-vs-20 is an open owner decision and is explicitly
 * not settled here. Authoring each league's CURRENT derived size is precisely what avoids
 * pre-empting it: the figure becomes a decision the admin can edit
 * (`LeagueAdminController::quickEdit()`, blank = 0 = derive) without this release having made it.
 *
 * ── 1. ONLY WHERE `slots = 0`, AND THAT IS NOT A DETAIL ───────────────────────────────────
 *
 * An already-authored `slots` is somebody's decision, and overwriting it with a live club count
 * is the exact side effect this migration exists to abolish — it would be the defect, applied
 * deliberately.
 *
 * It is also not hypothetical. Measured on the development clone, 2026-11-19: all 120 league rows
 * are already authored there, and **SC tier 8 holds an authored 3 while deriving 4** (3 NPC rows
 * plus the player's slot). "Author every league to its derived size" would therefore have moved
 * SC tier 8 from 3 to 4 — a real, unauthored economic change, in the one change whose promise is
 * that the day it lands is byte-identical.
 *
 * So on that clone this migration writes NOTHING, and on the deployed box — where `slots` is 0 —
 * it authors the counts that box is already using. Both are no-ops in behaviour.
 *
 * ── 2. COMPUTED IN SQL, NOT HARDCODED ─────────────────────────────────────────────────────
 *
 * The derived size differs per box: the clone has EN at 18 club rows per tier, the deployed box
 * has 16 (`r2-closeout-report.md`'s stop-the-line section). A hardcoded table would therefore
 * author the WRONG count on at least one of them and silently re-price it — reintroducing the
 * defect while claiming to fix it. Computing it at migration time means each box authors exactly
 * what it was already deriving, whatever that is.
 *
 * ── 3. THE DERIVATION, MIRRORED EXACTLY ───────────────────────────────────────────────────
 *
 * Three details are copied from the resolver rather than reinvented, because a divisor that
 * disagrees with the one it replaces is worse than no change at all:
 *
 *  - **`n.country = lg.country` on the join.** `NpcClubRepository::countsByLeagueForCountry()`
 *    filters on the club's OWN country as well as its league, so a club whose country disagrees
 *    with its league's is not counted. A naive join on `league_id` alone would count it.
 *  - **`MAX(tier)` per country is the bottom tier.** `LeagueSizeResolver::isBottomTier()` asks
 *    `LeagueRepository::findLowestTierForCountry()`, which is `ORDER BY tier DESC LIMIT 1` — the
 *    deepest EXISTING row, deliberately not `country_profile.league_tier_count`, so a declared
 *    depth of 8 with only 4 leagues built does not give the extra slot to a tier nobody has.
 *  - **`+ 1` only at that bottom tier**, which is the player's own slot.
 *
 * ── 4. THE TWO BOUNDS raw SQL WOULD OTHERWISE BYPASS ──────────────────────────────────────
 *
 *  - **`LEAST(..., 40)`** — `League::setSlots()` caps at 40 and an `UPDATE` does not go through
 *    it. The widest real league here is 18, so the cap should never bind; writing it anyway is
 *    what keeps the column's invariant true however the row was written.
 *  - **`derived > 0`** — 0 still means derive, so authoring a 0 would author nothing while
 *    looking like a decision. An empty non-bottom tier is left deriving, which is the honest
 *    answer and the one `sizeOf()` already gives for it.
 *
 * ── 5. NOT A PACK-SHAPE CHANGE ────────────────────────────────────────────────────────────
 *
 * `WORLD_PACK_VERSION` is deliberately NOT bumped. No key moves, no published figure changes, and
 * on every box the resolved size is identical before and after — there is nothing for a cache to
 * be stale about. `clubCount` is already published (`/api/initialize/leagues`, the sync snapshot)
 * and already carries `sizeOf()`'s answer, which this does not alter.
 */
final class Version20261119090000 extends AbstractMigration
{
    /**
     * The authoring statement, exposed so a test can run the REAL one.
     *
     * `wunderkind_test` is built by `doctrine:schema:create` from entity metadata and is not
     * migration-tracked (`.context` `environment.md`), so a migration never reaches it and
     * cannot be covered by running it. Re-typing the SQL in the test would cover a copy — and a
     * divisor whose test and whose migration disagree is precisely the failure worth catching.
     * `LeagueSlotsAuthoringTest` requires this file by path (migrations are deliberately not
     * autoloaded, see `config/packages/doctrine_migrations.yaml`) and executes this constant.
     */
    public const AUTHOR_SQL = <<<'SQL'
        UPDATE league l
           SET slots = LEAST(d.derived, 40)
          FROM (
                SELECT lg.id AS id,
                       COUNT(n.id)
                         + CASE WHEN lg.tier = m.deepest THEN 1 ELSE 0 END AS derived
                  FROM league lg
                  JOIN (
                        SELECT country, MAX(tier) AS deepest
                          FROM league
                         GROUP BY country
                       ) m ON m.country = lg.country
                  LEFT JOIN npc_club n
                         ON n.league_id = lg.id
                        AND n.country   = lg.country
                 GROUP BY lg.id, lg.tier, m.deepest
               ) d
         WHERE d.id = l.id
           AND l.slots = 0
           AND d.derived > 0
        SQL;

    public function getDescription(): string
    {
        return 'Author league.slots to each league\'s currently derived size wherever it is still 0 (0 = derive), so the division size is a decision rather than a side effect of the live club count — stashing every row it replaces. Behaviour-identical on every box.';
    }

    public function up(Schema $schema): void
    {
        // THE STASH FIRST, AND IT STASHES EVERY ROW, not only the ones about to be written.
        // `down()` can then restore the column wholesale without having to know which rows this
        // migration touched, which is the same reason `Version20261118120000` stashes all six
        // figures rather than the ones it happens to change.
        $this->addSql(<<<'SQL'
            CREATE TABLE league_slots_pre_authoring_backup (
                league_id  UUID    NOT NULL,
                slots      SMALLINT NOT NULL,
                captured_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                PRIMARY KEY (league_id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO league_slots_pre_authoring_backup (league_id, slots)
            SELECT id, slots FROM league
            SQL);

        // ── THE AUTHORING. See §3 for why each clause is the shape it is. ────────────────
        $this->addSql(self::AUTHOR_SQL);
    }

    public function down(Schema $schema): void
    {
        // RESTORED FROM THE STASH, not reset to 0: a box may have had rows authored before this
        // migration ran — the development clone had all 120 — and resetting those to "derive"
        // would be a rollback that loses authored decisions rather than undoing this change.
        $this->addSql(<<<'SQL'
            UPDATE league l
               SET slots = b.slots
              FROM league_slots_pre_authoring_backup b
             WHERE b.league_id = l.id
            SQL);

        $this->addSql('DROP TABLE league_slots_pre_authoring_backup');
    }
}
