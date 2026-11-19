<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Correct `league.slots` where an AUTHORED value disagrees with the division's real size.
 *
 * ⚠️ **THIS ONE IS A BEHAVIOUR CHANGE, DELIBERATELY, AND IT CARRIES AN OWNER DECISION.**
 * `Version20261119090000` authored `slots` only where it was 0 and explicitly refused to touch an
 * already-authored value, on the correct grounds that overwriting somebody's decision is the very
 * side effect that change existed to abolish. This migration is the other half, and it exists
 * because the owner has since made the decision that was missing:
 *
 *   **`slots` equals the division's real size. 18 was never anybody's decision — it is residue
 *   from the spoof-club purge, which took club rows down and left `slots` where it was.**
 *
 * Settled 2026-11-19. It does NOT settle 16-vs-20, which is a separate open question about what
 * to GENERATE: choose 20 later, regenerate to 20 rows, and this same rule still holds.
 *
 * ── WHAT IT CORRECTS, MEASURED ON THE DEPLOYED BOX ────────────────────────────────────────
 *
 * From the 2026-11-18 22:00 world export (200 leagues, 3,191 clubs, 25 countries) the world
 * partitions exactly 184 + 12 + 4:
 *
 *   EN tiers 1-7   slots 18, 16 real rows              -> 16
 *   EN tier 8      slots 18, 15 real rows + reserved   -> 16
 *   SC tiers 1-4   slots 18, 14 real rows              -> 14
 *   184 others     already agree                       -> untouched
 *   SC tiers 5-8   slots 0                             -> untouched, Version20261119090000 owns them
 *
 * `sizeOf()` has therefore been returning 18 for divisions that actually play 16 and 14. No
 * payout money moves — `LeaguePayoutResolver` never divides by size — but three things that exist
 * to tell us when something is wrong were being fed a false divisor: **check 8's per-club
 * arithmetic** in `app:economy:wage-table`, the **published `clubCount`**, and the app's own
 * `tvPoolPence / clubCount` cross-check in `seasonFinances.ts`. This recalibrates instruments.
 *
 * ── ⚠️ THE RESERVED PLAYER SLOT IS DETECTED, NOT ASSUMED, AND THAT IS THE WHOLE CARE HERE ──
 *
 * `LeagueSizeResolver::sizeOf()` adds 1 at a country's deepest tier, because the world generator
 * leaves one place open for the player's club — true in 25 of 25 countries on the deployed box,
 * where EN runs 16 rows at tiers 1-7 and 15 at tier 8.
 *
 * **It is NOT universally true, and an earlier draft of this migration would have corrupted six
 * rows because of it.** Measured on the development clone, 2026-11-19: EN reserves (18/18/…/17),
 * but **CA, JP, NG, PL, US and SC do not** — every tier including the deepest holds the same
 * count (8, 8, 3). There `rows + 1` is one MORE than the division can field, so "author the
 * derived size" would have written 9 over a correct 8, and 4 over SC's correct 3.
 *
 * So the target is computed as:
 *
 *   rows + 1   ONLY at the deepest tier AND ONLY when that tier holds exactly one fewer row
 *              than the tier above it — i.e. when a place was demonstrably reserved
 *   rows       otherwise
 *
 * A country with a single tier cannot show a reservation and is treated as unreserved, which is
 * the conservative answer. **On the development clone this migration is a complete no-op, and
 * that is verifiable rather than asserted** — see `LeagueSlotsCorrectionTest`.
 *
 * ── A DEFECT THIS EXPOSES AND DOES NOT FIX ────────────────────────────────────────────────
 *
 * `sizeOf()`'s unconditional `+1` is wrong on any box where the deepest tier was not reserved. It
 * is inert wherever `slots` is authored (the authored value wins), so it bites only `slots = 0`
 * leagues — on the deployed box that is SC tiers 5-8, which DO reserve, so nothing is wrong
 * there today. **Reported, not fixed here: changing the resolver is a wider change than a
 * data correction and is not this migration's business.**
 */
final class Version20261119120000 extends AbstractMigration
{
    /**
     * Exposed as a constant so the test can run the migration's OWN statement:
     * `wunderkind_test` is not migration-tracked, and a re-typed copy would test the copy.
     */
    public const CORRECT_SQL = <<<'SQL'
        UPDATE league l
           SET slots = LEAST(t.target, 40)
          FROM (
                SELECT r.id AS id,
                       r.rowcount
                         + CASE
                             WHEN r.tier = r.deepest
                              AND above.rowcount IS NOT NULL
                              AND r.rowcount = above.rowcount - 1
                             THEN 1 ELSE 0
                           END AS target
                  FROM (
                        SELECT lg.id,
                               lg.country,
                               lg.tier,
                               m.deepest,
                               COUNT(n.id) AS rowcount
                          FROM league lg
                          JOIN (
                                SELECT country, MAX(tier) AS deepest
                                  FROM league
                                 GROUP BY country
                               ) m ON m.country = lg.country
                          LEFT JOIN npc_club n
                                 ON n.league_id = lg.id
                                AND n.country   = lg.country
                         GROUP BY lg.id, lg.country, lg.tier, m.deepest
                       ) r
                  LEFT JOIN (
                        SELECT lg.country, lg.tier, COUNT(n.id) AS rowcount
                          FROM league lg
                          LEFT JOIN npc_club n
                                 ON n.league_id = lg.id
                                AND n.country   = lg.country
                         GROUP BY lg.country, lg.tier
                       ) above
                         ON above.country = r.country
                        AND above.tier    = r.tier - 1
               ) t
         WHERE t.id = l.id
           AND l.slots > 0
           AND l.slots <> t.target
           AND t.target > 0
        SQL;

    public function getDescription(): string
    {
        return 'Correct league.slots where an AUTHORED value disagrees with the division\'s real size (spoof-purge residue: EN 18 against 16 rows, SC tiers 1-4 18 against 14). Carries the owner decision that slots tracks the real size; detects the reserved player slot rather than assuming it, so a country whose deepest tier was never reserved is left alone. A behaviour change: check 8\'s divisor and the published clubCount both move.';
    }

    public function up(Schema $schema): void
    {
        // EVERY ROW IS STASHED, not only the ones about to be written, so `down()` restores the
        // column wholesale without needing to know what this migration touched. Same shape as
        // `Version20261119090000` and `Version20261118120000`, and a DIFFERENT table name so the
        // two can coexist and roll back independently.
        $this->addSql(<<<'SQL'
            CREATE TABLE league_slots_pre_correction_backup (
                league_id   UUID     NOT NULL,
                slots       SMALLINT NOT NULL,
                captured_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                PRIMARY KEY (league_id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO league_slots_pre_correction_backup (league_id, slots)
            SELECT id, slots FROM league
            SQL);

        $this->addSql(self::CORRECT_SQL);
    }

    public function down(Schema $schema): void
    {
        // RESTORED FROM THE STASH, never reset to 0. A rollback that turned an authored column
        // into "derive" would lose decisions this migration never made.
        $this->addSql(<<<'SQL'
            UPDATE league l
               SET slots = b.slots
              FROM league_slots_pre_correction_backup b
             WHERE b.league_id = l.id
            SQL);

        $this->addSql('DROP TABLE league_slots_pre_correction_backup');
    }
}
