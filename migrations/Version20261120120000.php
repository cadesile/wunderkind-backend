<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the two AGE-BAND judgement keys — `youth` and `senior` — to every existing scout, and
 * re-derives `scout.ability` for every row behind them in the same migration.
 *
 * Owner ruling, 2026-11-20, asked whether scouting accuracy should vary by the player's age:
 * **"Yes, it should."**
 *
 * ⚠️ **THIS TURNS ON A FEATURE THAT HAS NEVER ONCE RUN.** The app's `ageGateFor()`
 * (`src/engine/scoutNoise.ts`) reads `judgements.youth` for players under 18 and
 * `judgements.senior` at 18 and above. Neither key was ever written by
 * `ScoutGenerationService::build()` — the only path that has ever written a persisted scout —
 * so the function's `: 0` fallback was the only branch that ever executed, for all 5,278 scouts
 * in the dev database and every scout in every save. With `AGE_GATE_WEIGHT = 0.3` that had two
 * effects, and they agreed with each other, which is why nothing looked broken:
 *   - DISPLAYED accuracy (`scoutAccuracyPercent`, `potential x 0.7 + ageGate x 0.3`) was 70% of
 *     its designed value — a perfect 100-potential scout showed 70%;
 *   - the real noise band (`applyScoutNoise`) was never tightened, so every scout read up to 30%
 *     less accurately than designed.
 * Scouts therefore get VISIBLY BETTER when this lands. That is the fix working, not inflation.
 *
 * ⚠️ **NO DDL. `scout.judgements` is a `json` column, so this is a data migration only.** It
 * needs no hand-application to the untracked `wunderkind_test` database and cannot put the ORM
 * mapping out of sync — contrast `Version20261120100000`, which added a column.
 *
 * ⚠️ **THE TWO KEYS SHARE THE OTHER FIVE'S BOUNDS, DELIBERATELY** — `pool_config`'s
 * `scout_judgement_min/max` (15-90 on dev; the entity's own defaults, 40/80, are the fallback
 * here exactly as `PoolConfigRepository::getConfig()` falls back to `new PoolConfig()`). They are
 * judgements on the same 0-100 scale, and a separate pair of bounds would be a second config
 * surface for one quantity. See `ScoutGenerationService::build()` for the full reasoning.
 *
 * ⚠️ **THE SECOND STATEMENT IS WHY THIS IS ONE MIGRATION AND NOT TWO.** `ScoutAbilityCalculator`
 * averages judgements OVER THE KEYS PRESENT, so going from five keys to seven changes the
 * judgement mean, and therefore `ability`, for every scout in the world — and A24 will price a
 * wage off that column. Writing the keys without re-deriving the ability would leave the stored
 * figure disagreeing with the formula that owns it: one quantity, two sources, nothing saying
 * which is right, which is the governing defect this whole workstream exists to remove. The
 * column is never in that state for a moment. `app:backfill-scout-abilities --force` is run
 * behind this anyway, and reporting 0 of 5,278 rows changed is the end-to-end proof that the SQL
 * here and the PHP formula agree.
 *
 * ⚠️ **THE ABILITY SQL IS A VERBATIM COPY OF `Version20261120100000`'s, AND MUST STAY THAT WAY.**
 * It is a frozen transcription of `ScoutAbilityCalculator`'s 70/30 blend, not a second home for
 * the formula: the mean is taken over `jsonb_each_text` (keys actually present, never a fixed
 * divisor), a row with no numeric judgements falls back to experience alone rather than a
 * fabricated 0, inputs are clamped 1-100 before the blend and the result after, and `round()` is
 * applied to a NUMERIC so it is half-away-from-zero and matches PHP on exact .5 cases. Casting to
 * `double precision` would reintroduce the banker's-rounding disagreement that
 * `ScoutAbilityCalculatorTest::testRoundsExactHalvesTheSameWayTheMigrationSqlDoes()` pins. If the
 * weighting is ever retuned, this statement stays as written and the command re-derives the
 * world.
 *
 * Two honest notes on what this migration is NOT:
 *   - **It is not reproducible.** `random()` rolls each scout's two values, exactly as
 *     `random_int()` does at generation. Re-running on another database gives different scouts,
 *     which is true of every generated figure in this schema.
 *   - **It normalises `judgements` key order.** The `jsonb` round-trip needed for `||` sorts keys
 *     alphabetically, so an existing row's stored order changes. Nothing reads these by position:
 *     the mean is order-independent, the app selects by name through its own `SCOUT_JUDGEMENTS`
 *     list, and the only key-order assertion anywhere is
 *     `ScoutGenerationServiceTest::testJudgementsAreTheSevenAuthoritativeKeys()`, which checks a
 *     freshly built entity and never touches the database.
 *
 * `WorldInitializationService::WORLD_PACK_VERSION` goes 12 -> 13 in the same change, because
 * judgements ride in the world pack and a pack cached at v12 carries five keys. Legitimate under
 * the pack-is-a-census rule: this is ability-shaped census content, not money.
 */
final class Version20261120120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add youth/senior age-band judgements to every scout and re-derive ability behind them.';
    }

    public function up(Schema $schema): void
    {
        // Idempotent by construction: a row already carrying both keys is left alone, so a
        // partially-applied run can be repeated without re-rolling anyone's values.
        //
        // ⚠️ `jsonb_exists(x, 'key')` AND NOT THE `?` OPERATOR, AND NOT AS A STYLE CHOICE.
        // DBAL's SQL parser treats a bare `?` as a positional bind placeholder, so the operator
        // form reaches Postgres as `... jsonb $1 'youth'` and the migration dies on a syntax
        // error. The function is the same test by another name.
        $this->addSql(<<<'SQL'
            WITH cfg AS (
                SELECT LEAST(lo, hi) AS lo, GREATEST(lo, hi) AS hi
                FROM (
                    SELECT COALESCE((SELECT scout_judgement_min FROM pool_config ORDER BY id LIMIT 1), 40) AS lo,
                           COALESCE((SELECT scout_judgement_max FROM pool_config ORDER BY id LIMIT 1), 80) AS hi
                ) raw
            )
            UPDATE scout s
            SET judgements = (
                (s.judgements::jsonb) || jsonb_build_object(
                    'youth',  (cfg.lo + floor(random() * (cfg.hi - cfg.lo + 1))::int),
                    'senior', (cfg.lo + floor(random() * (cfg.hi - cfg.lo + 1))::int)
                )
            )::json
            FROM cfg
            WHERE NOT jsonb_exists(s.judgements::jsonb, 'youth')
               OR NOT jsonb_exists(s.judgements::jsonb, 'senior')
            SQL);

        // Verbatim from Version20261120100000 — see the class docblock. The judgement mean has
        // just changed for every row, so every ability must follow it in the same migration.
        $this->addSql(<<<'SQL'
            UPDATE scout s
            SET ability = GREATEST(1, LEAST(100, ROUND(
                CASE
                    WHEN j.mean IS NULL THEN GREATEST(1, LEAST(100, s.experience::numeric))
                    ELSE 0.7 * GREATEST(1, LEAST(100, s.experience::numeric))
                       + 0.3 * GREATEST(1, LEAST(100, j.mean))
                END
            )))
            FROM (
                SELECT sc.id,
                       (
                           SELECT AVG(e.value::numeric)
                           FROM jsonb_each_text(sc.judgements::jsonb) AS e(key, value)
                           WHERE e.value ~ '^-?[0-9]+(\.[0-9]+)?$'
                       ) AS mean
                FROM scout sc
            ) AS j
            WHERE j.id = s.id
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Strips the two keys back out and re-derives ability over the remaining five, so the
        // column still agrees with the formula after a rollback. The rolled values themselves are
        // not recoverable — `up()` is not reproducible, and re-running it rolls fresh ones.
        $this->addSql(<<<'SQL'
            UPDATE scout s
            SET judgements = ((s.judgements::jsonb) - 'youth' - 'senior')::json
            WHERE jsonb_exists(s.judgements::jsonb, 'youth')
               OR jsonb_exists(s.judgements::jsonb, 'senior')
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE scout s
            SET ability = GREATEST(1, LEAST(100, ROUND(
                CASE
                    WHEN j.mean IS NULL THEN GREATEST(1, LEAST(100, s.experience::numeric))
                    ELSE 0.7 * GREATEST(1, LEAST(100, s.experience::numeric))
                       + 0.3 * GREATEST(1, LEAST(100, j.mean))
                END
            )))
            FROM (
                SELECT sc.id,
                       (
                           SELECT AVG(e.value::numeric)
                           FROM jsonb_each_text(sc.judgements::jsonb) AS e(key, value)
                           WHERE e.value ~ '^-?[0-9]+(\.[0-9]+)?$'
                       ) AS mean
                FROM scout sc
            ) AS j
            WHERE j.id = s.id
            SQL);
    }
}
