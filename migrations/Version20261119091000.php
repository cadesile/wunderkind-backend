<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Author `game_config.bankruptcy_deduction_weeks` — the deficit ladder, in weeks of wages.
 *
 * ── WHAT THIS IS, AND WHAT IT IS NOT ──────────────────────────────────────────────────────
 *
 * This is an AUTHORING migration and nothing else. The mechanism it fills in already exists and
 * has since 2026-11-13 (`Version20261113120000`): a nullable column, publication through
 * `EconomyConfigPublisher::clientBlock()` to `GET /api/game-config` and both sync branches, a
 * JSON textarea on the game-config admin screen where blank means null, normalisation in
 * `GameConfig::setBankruptcyDeductionWeeks()`, and reporting in `app:economy:wage-table`'s
 * check 5. What was missing was the figure: the column ships `null`.
 *
 * ── WHY IT IS WORTH AUTHORING NOW ─────────────────────────────────────────────────────────
 *
 * `bankruptcyDeductionTiers`, the pence ladder this supersedes for the turnover model, is
 * authored in flat absolute pence with no tier dimension. Its harshest band begins at
 * £40,000.01 of deficit against a tier-1 weekly wage bill of £3,238,043 — so the entire
 * five-rung ladder is traversed in 0.01 weeks. The mechanic is not "a graduated penalty for
 * financial trouble", it is "any deficit at all = maximum penalty". The bill depends on nothing
 * but the turnover row, so this reads identically on dev and read WORSE before R2
 * (£3,801,742/wk). `app:economy:wage-table` reports it live at tiers 1-5 of both gated
 * countries, today.
 *
 * Same defect class as the flat youth scholarship and the flat staff role bands, both already
 * fixed by adding a tier dimension. This is the third instance and the last one open — and
 * weeks-of-wages is a stronger fix than a tier dimension would be, because it cannot decouple
 * at all: a country factor, a tier factor, inflation and a wage-model flip each move the
 * numerator and the denominator together. `npcSolvencyThresholds` already works this way and is
 * the model being copied.
 *
 * ── THE LADDER, AND WHOSE IT IS ───────────────────────────────────────────────────────────
 *
 * Exactly the five rows `docs/economy-threshold-review.md`'s proposal specifies. They are NOT
 * invented here, and deliberately not re-derived: the shape was agreed with the app team, and
 * the app carries the identical ladder as its own fallback constant
 * (`src/economy/insolvency.ts`'s `BANKRUPTCY_WEEKS_LADDER`, verified read-only 2026-11-19 and
 * byte-identical to these rows).
 *
 *   weeks  1-2    2-4    4-8    8-16   16+
 *   points 3      6      10     20     50
 *
 * CONTIGUOUS, NOT MERELY NON-OVERLAPPING: each `weeksMin` equals the previous `weeksMax`, so the
 * upper bound reads as exclusive and every deficit lands in exactly one row. That is the contract
 * `normaliseDeductionWeeks()` enforces, so these rows store unaltered — and it is what makes the
 * first-match-vs-last-match question that killed two of the pence rows stop existing.
 *
 * ── ⚠️ WHY THIS IS A NO-OP, AND WHY THAT HAD TO BE CHECKED RATHER THAN ASSUMED ────────────
 *
 * Authoring this column is NOT inert in the way a new key is: `resolveWeeksLadder` reads the
 * published ladder when it is valid and falls back to the app's own constant otherwise, so
 * authoring flips every client from its constant to this. That is a real behaviour change unless
 * the two agree — which is why the app's constant was read and compared before writing this,
 * rather than trusted from the agreement note. They are identical, so no client's behaviour
 * moves. The mechanic is also still gated by `bankruptcyDeductionsEnabled`.
 *
 * ── ⚠️ `bankruptcyDeductionTiers` IS DELIBERATELY UNTOUCHED ───────────────────────────────
 *
 * Same name, same type, same values, still published, still verbatim — it remains the ladder for
 * the `legacy` model. The review is explicit that the weeks proposal is ADDITIVE and does not
 * redefine a published key, and amending the pence ladder's bounds is called out there as a value
 * change not made.
 *
 * So the two gate problems about ITS rows 4 and 5 being unreachable behind row 3's null
 * `deficitMax` SURVIVE this migration, and that is correct rather than an oversight: they are
 * pre-existing, they belong to the legacy ladder, and fixing them means changing published
 * values that shipped builds read. The five per-tier "no range left" problems survive for the
 * same reason — they measure the pence ladder.
 *
 * ── REVERSIBILITY ─────────────────────────────────────────────────────────────────────────
 *
 * `up()` stashes the column first, as `Version20261118120000` does, so `down()` restores exactly
 * what the box was holding rather than assuming it was null. A box where an operator had already
 * authored a ladder by hand therefore rolls back to THEIR ladder, not to NULL.
 *
 * Not a pack-shape change: this is published config, not baked into a world pack, so
 * `WORLD_PACK_VERSION` is not bumped.
 */
final class Version20261119091000 extends AbstractMigration
{
    /**
     * The agreed ladder. Contiguous: each `weeksMin` is the previous `weeksMax`.
     *
     * Written as a JSON literal rather than built with `json_encode` so that what this migration
     * writes is readable in the file, which is the same reason `Version20261118120000` spells its
     * ladders out.
     */
    private const WEEKS_LADDER = '[{"weeksMin":1,"weeksMax":2,"points":3},{"weeksMin":2,"weeksMax":4,"points":6},{"weeksMin":4,"weeksMax":8,"points":10},{"weeksMin":8,"weeksMax":16,"points":20},{"weeksMin":16,"weeksMax":null,"points":50}]';

    public function getDescription(): string
    {
        return 'Author game_config.bankruptcy_deduction_weeks with the agreed five-row weeks-of-wages deficit ladder (1-2/2-4/4-8/8-16/16+ at 3/6/10/20/50 points), stashing whatever it replaces. Byte-identical to the app\'s own fallback constant, so no client behaviour moves; bankruptcyDeductionTiers is untouched and remains the legacy ladder.';
    }

    public function up(Schema $schema): void
    {
        // THE STASH FIRST, so nothing is overwritten before it is recorded — and so a rollback
        // restores an operator's hand-authored ladder rather than resetting to NULL.
        $this->addSql(<<<'SQL'
            CREATE TABLE bankruptcy_weeks_pre_authoring_backup (
                id                        SERIAL PRIMARY KEY,
                bankruptcy_deduction_weeks JSON DEFAULT NULL,
                captured_at               TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO bankruptcy_weeks_pre_authoring_backup (bankruptcy_deduction_weeks)
            SELECT bankruptcy_deduction_weeks FROM game_config
            SQL);

        $this->addSql(sprintf(
            "UPDATE game_config SET bankruptcy_deduction_weeks = '%s'",
            self::WEEKS_LADDER,
        ));
    }

    public function down(Schema $schema): void
    {
        // NO COALESCE HERE, unlike the R2 migration's numeric columns: NULL is this column's own
        // meaningful "nothing authored" state — it means "the app uses its own ladder" — so
        // restoring a stashed NULL as NULL is exact rather than lossy.
        $this->addSql(<<<'SQL'
            UPDATE game_config g
               SET bankruptcy_deduction_weeks = b.bankruptcy_deduction_weeks
              FROM (SELECT * FROM bankruptcy_weeks_pre_authoring_backup ORDER BY id LIMIT 1) b
             WHERE TRUE
            SQL);

        $this->addSql('DROP TABLE bankruptcy_weeks_pre_authoring_backup');
    }
}
