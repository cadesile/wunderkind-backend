<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Resync `league.tv_deal` — the per-club TV cache that `Version20261119120000` left stale.
 *
 * ── WHAT WENT STALE, AND WHY NOTHING NOTICED ──────────────────────────────────────────────
 *
 * `tv_deal` is not an independent figure wherever a pool exists: it is a CACHE of
 * `tvPoolPence / slots`, and `LeagueSizeResolver::syncTvDeal()` is the only thing that writes
 * it. That method runs on an **admin save only** (`LeagueAdminController:95`/`:201`) — the
 * comments at `InitializeController:249` and `SyncService:832` both say so. So when
 * `Version20261119120000` corrected `slots` (EN 18 -> 16, SC tiers 1-4 18 -> 14) the figure
 * derived from `slots` was left encoding the OLD divisor, and no code path re-ran the sync.
 *
 * Measured on the deployed box from the world export of 2026-11-19:
 *
 *   EN t1  tv_pool_pence = 30000000000
 *   EN t1  resolved tvDeal = 1875000000   = pool / 16   <- what every client reads
 *   EN t1  STORED tv_deal  = 1666666666   = pool / 18   <- this column, stale
 *
 * **Clients were never affected** and that is worth stating, because it is what makes this a
 * tidy-up rather than an incident: all four publication sites resolve
 * `derivedTvDealPence() ?? getTvDeal()` (`SyncService:834`, `InitializeController:251`,
 * `LeagueService:306`, `LeagueImportExportService:66`), so the stale column is invisible
 * downstream. The one reader that did NOT resolve was the release gate,
 * `app:economy:wage-table`, which paired this column with the *corrected* `sizeOf()` and so
 * read England's TV income 12.5% low while printing a row where `perClub x size != pool`.
 * **That reader is fixed in code in the same commit as this migration, and the code fix is the
 * half that matters** — it removes the gate from this whole class of defect rather than this
 * one instance of it. This migration exists because the column is a published cache and the
 * next reader to appear should not have to know its history.
 *
 * ── ⚠️ ONLY WHERE A POOL EXISTS, AND THAT IS THE WHOLE CARE HERE ─────────────────────────
 *
 * `WHERE tv_pool_pence IS NOT NULL`. A league with no pool has no derivation to cache, so its
 * `tv_deal` is an AUTHORED absolute that an operator typed — the per-club figure released
 * builds have always read. Writing a derived number over it would be inventing a pool that
 * nobody authored, and it is exactly the case `derivedTvDealPence()` returns null for. The
 * null-is-inert contract is mirrored here deliberately: for a pool-less league this migration
 * is provably a no-op, which is what makes it safe to ship without auditing every row.
 *
 * `slots > 0` for the same reason in reverse: `slots = 0` means "derive", and `intdiv` by zero
 * is not a figure. Such a league's size is resolved at read time from NPC rows, which SQL here
 * has no business second-guessing — `derivedTvDealPence()` already returns null when the size
 * does not resolve rather than publishing the whole pool as one club's share.
 *
 * `intdiv` semantics: PostgreSQL's `/` on two bigints already truncates toward zero, which is
 * what PHP's `intdiv()` does, so the stored figure matches what `derivedTvDealPence()` computes
 * to the penny rather than to a rounding mode. Both operands are non-negative here anyway.
 *
 * ── NOT A PACK-SHAPE CHANGE ──────────────────────────────────────────────────────────────
 *
 * `WORLD_PACK_VERSION` is deliberately untouched. The published `tvDeal` key does not move:
 * every publication site was ALREADY serving `pool / slots`, so this writes into the column the
 * value clients were already being given. The only observable change is to readers of the raw
 * column, of which there was one, and it is corrected in code.
 */
final class Version20261119150000 extends AbstractMigration
{
    /**
     * Exposed as a constant so the test can run the migration's OWN statement:
     * `wunderkind_test` is not migration-tracked, so a re-typed copy would test the copy.
     * Same reasoning, and same shape, as `Version20261119120000::CORRECT_SQL`.
     */
    public const RESYNC_SQL = <<<'SQL'
        UPDATE league
           SET tv_deal = tv_pool_pence / slots
         WHERE tv_pool_pence IS NOT NULL
           AND slots > 0
           AND (tv_deal IS NULL OR tv_deal <> tv_pool_pence / slots)
        SQL;

    public function getDescription(): string
    {
        return 'Resync league.tv_deal to tvPoolPence / slots wherever a pool exists, which Version20261119120000 left stale when it corrected slots (EN stored pool/18 against a corrected size of 16). A league with no pool keeps its authored per-club figure untouched, mirroring derivedTvDealPence()\'s null-is-inert contract. No published figure moves: all four publication sites already resolved the derived value, so this only corrects the raw column and the one reader of it.';
    }

    public function up(Schema $schema): void
    {
        // EVERY ROW IS STASHED, not only the ones about to be written, so `down()` restores the
        // column wholesale without needing to know what this migration touched. Same shape as
        // `Version20261119120000`, and a DIFFERENT table name so the two can coexist and roll
        // back independently.
        $this->addSql(<<<'SQL'
            CREATE TABLE league_tv_deal_pre_resync_backup (
                league_id   UUID NOT NULL,
                tv_deal     BIGINT,
                captured_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                PRIMARY KEY (league_id)
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO league_tv_deal_pre_resync_backup (league_id, tv_deal)
            SELECT id, tv_deal FROM league
            SQL);

        $this->addSql(self::RESYNC_SQL);
    }

    public function down(Schema $schema): void
    {
        // RESTORED FROM THE STASH, never recomputed. A rollback that recomputed would be the
        // same bug in reverse: it would overwrite a figure an operator authored between the two
        // runs with one derived from whatever `slots` says at rollback time. The stash is
        // nullable because `tv_deal` is, and a league that held NULL must go back to NULL.
        $this->addSql(<<<'SQL'
            UPDATE league l
               SET tv_deal = b.tv_deal
              FROM league_tv_deal_pre_resync_backup b
             WHERE b.league_id = l.id
            SQL);

        $this->addSql('DROP TABLE league_tv_deal_pre_resync_backup');
    }
}
