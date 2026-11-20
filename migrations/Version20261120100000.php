<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `scout.ability` — the scout's single 1-100 ability — and backfills every existing row
 * from its own `experience` and `judgements`.
 *
 * Owner ruling, 2026-11-20: *"add 'ability' value, 1-100, have it driven off experience and
 * judgements, to set a single figure."* The quantity was already being assumed by three app
 * sites that read `Scout.experience` as if it were ability; see `Scout::$ability` and
 * `ScoutAbilityCalculator` for the full account and the 70/30 weighting's reasoning.
 *
 * ⚠️ **THE BACKFILL IS IN THE MIGRATION, NOT BEHIND IT, AND THAT IS THE POINT.** The scout wage
 * column (2026-11-05) landed `DEFAULT 0` with `app:backfill-scout-wages` behind it, and its own
 * migration recorded that the default was "the only safe default for a migration but also a
 * lie". For ability a zero is worse than a lie: it is not a legal value at all — the clamp floor
 * is 1 — and A24 will price a wage off this column, so a window in which every scout reads 0
 * would price the entire world at the bottom of the band. Every row is therefore derived here,
 * in one statement, and the column is never in a state anything could misread.
 * `app:backfill-scout-abilities` still exists, for re-derivation after a config change and for
 * any row a later import leaves behind.
 *
 * ⚠️ **THE SQL BELOW IS A FROZEN TRANSCRIPTION OF THE FORMULA, NOT A SECOND HOME FOR IT.**
 * `ScoutAbilityCalculator` is canonical. A migration is a historical record and must not be
 * edited once applied, so if the weighting is ever retuned this statement stays as it is and
 * `app:backfill-scout-abilities --force` is what re-derives the world. Do not "fix" this SQL to
 * match a later formula — that would make an applied migration describe something it never did.
 *
 * The two agree at the moment of writing, and that agreement is PINNED by
 * `ScoutAbilityCalculatorTest::testRoundsExactHalvesTheSameWayTheMigrationSqlDoes()`. It is
 * pinned because it had already broken: the calculator's first cut computed `0.7 * e + 0.3 * m`
 * in binary floats, which lands just under on inputs whose true result is exactly k.5, and PHP
 * disagreed with this SQL on 6 of the 5,278 dev scouts. The calculator now multiplies through by
 * the weights' denominator and divides once, so both sides round half-away-from-zero on exact
 * decimal arithmetic. `app:backfill-scout-abilities --force --dry-run` reports 0 of 5,278 rows
 * changed, which is the end-to-end check that they still agree.
 *
 * Arithmetic notes, because they are what makes the two agree:
 *   - the judgement mean is taken over `jsonb_each_text`, i.e. over the keys the row ACTUALLY
 *     has. Persisted scouts carry 5 (`ScoutGenerationService`), `GenerateMarketDataCommand`
 *     writes 7, and a fixed divisor would under-rate every real scout by ~29%;
 *   - a row whose `judgements` is empty or wholly non-numeric falls back to experience alone,
 *     never to a fabricated 0 for that half of the blend;
 *   - inputs are clamped to 1-100 before blending and the result after, matching the calculator;
 *   - `round()` is applied to a NUMERIC, not a float, so it is half-away-from-zero on both
 *     sides. Casting to `double precision` here would introduce banker's-rounding disagreements
 *     on exact .5 values.
 *
 * `NOT NULL` with a DB-level `DEFAULT 1` that is **KEPT**, matching `Scout::$ability`'s own
 * `options: ['default' => 1]`.
 *
 * ⚠️ **It is deliberately NOT dropped, and the first cut of this migration got that wrong.**
 * `Version20261119190000` and the `starter_config` migrations before it add a default and then
 * drop it — but those columns' entities declare no default, so for them the DB default IS
 * mapping drift. Here the entity declares one, so dropping it is the drift, in the other
 * direction: `SchemaIsInSyncTest::testOrmMappingMatchesTheMigratedDatabase` failed with
 * `ALTER TABLE scout ALTER ability SET DEFAULT 1` outstanding. `scout.experience` and
 * `scout.weekly_salary` both keep a DB default of 0 beside their entity's `default => 0`, and
 * this column follows them. **The rule is "the DB must match the entity", not "always drop the
 * default".**
 */
final class Version20261120100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scout.ability (1-100) and derive it for every existing scout from experience + judgements.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scout ADD ability INT NOT NULL DEFAULT 1');

        // One statement, every row. See the class docblock: this is a frozen transcription of
        // ScoutAbilityCalculator's 70/30 blend, not a second home for the formula.
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

        // NO `DROP DEFAULT` — see the class docblock. The entity declares `default => 1`, so the
        // DB default is what keeps the ORM mapping in sync, exactly as it does for
        // `experience` and `weekly_salary`.
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scout DROP ability');
    }
}
