<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A25 — gives STAFF ABILITY its own authored per-country, per-tier table, and seeds it for
 * every country that has a pyramid.
 *
 * Owner ruling, 2026-11-20, three limbs: staff ability gets its own authored table replacing
 * both the flat globals and the borrow-the-player-band workaround; a staff ability is defined
 * **against the player `current_ability` scale**; and a 4-tier country must be authored at four
 * tiers rather than padded to eight. Brief and ruling:
 * `wunderkind-docs/economy-loose-ends/a25-staff-ability-bands.md`.
 *
 * ── WHAT WAS WRONG ───────────────────────────────────────────────────────────────────────
 *
 * Staff ability came from two flat global integers, `pool_config.coach_ability_min/max`.
 * ⚠️ **The entity docblock said 40-75; the live dev row says 4-75** — the fifth instance of
 * this workstream's governing trap, after `bondDecayFloor`, `wageMultiplierTiers`,
 * `incomeAnchoredWages` and `league_ability_ranges` itself (whose live EN row is 40-90 down to
 * 2-7, not the committed 75-95 down to 10-25). A pair spanning 4 to 75 is not a band: a
 * bottom-tier club could be handed a 75 coach and a top-tier club a 4.
 *
 * The workaround was the other half of the defect. `StarterPackService` and both world-pack
 * generators passed the squad's PLAYER band into staff generation — and that table is
 * POTENTIAL. A staff member has no current/potential split, so a coach handed a potential band
 * sits AT that figure while the players beside him sit well below it.
 *
 * ── THE SEED, AND WHY IT IS DERIVED RATHER THAN CHOSEN ───────────────────────────────────
 *
 * Each tier's staff band is `[0.45 x midpoint, 0.85 x midpoint]` of THAT COUNTRY'S OWN authored
 * player band. The two fractions are measured, not picked: under the live dev configuration
 * 1,200 generated first-team players per tier land at a current ability of almost exactly
 * `0.6 x` their potential band's midpoint, with the 25th-95th percentile spanning
 * `0.45x`-`0.85x`, and that holds to within a point or two at every live tier. The full
 * measurement, and the residual against the committed default band (where it runs ~10% high at
 * tiers 1-3, because current ability compresses against high potential), are in
 * `wunderkind-docs/economy-loose-ends/a25-ability-bands-report.md`.
 *
 * Deriving it per country is what makes this a SEED rather than a tuning decision: it says
 * "staff at this tier are worth about what the players at this tier actually deliver", in the
 * country's own authored numbers, whatever those are. Picking different numbers is calibration
 * and is deliberately outside A25.
 *
 * ── DEPTH COMES FROM THE PLAYER TABLE, NOT FROM A CLUB OR LEAGUE COUNT ───────────────────
 *
 * A country is seeded with exactly the tiers its player band authors. On the live row that is
 * four for SC and eight for the other 25 countries, with no dead rows. `league_ability_ranges`
 * is used as the depth because `StarterAbilityRangeService::syncToPyramid()` already keeps it
 * in step with the pyramid and the app's country picker already counts its keys as a country's
 * depth — so there is one answer to "how deep is this country", not two that can disagree.
 * `StaffAbilityRangeService::syncToPyramid()` keeps the new table on that same leash from here.
 *
 * ── NOTES ON WHAT THIS MIGRATION IS AND IS NOT ───────────────────────────────────────────
 *
 * ⚠️ **IT ADDS A COLUMN, so it MUST be hand-applied to the untracked `wunderkind_test`
 * database** before the PHPUnit suite will pass — contrast `Version20261120120000`, which was
 * data-only. That is recorded in the report.
 *
 * - **It changes no existing column and moves no existing row.** `coach_ability_min/max`
 *   survives untouched as the UNTIERED band for the shared hire-market pool, which is one row
 *   served to every country at every tier and so has no tier to be banded against — the same
 *   reason `scout_wage_min/max` survived A24's prediction that it would become dead config.
 *   It is narrowed and relabelled in the admin, not retired.
 * - **The `staff` and `scout` tables need no backfill, and none is attempted here.** Every
 *   persisted row in them is a shared-pool row — `staff` has no club foreign key at all — and
 *   the untiered band is unchanged, so no stored figure is made stale by this. A club's staff
 *   exist only inside cached world packs and starter packs, and
 *   `WorldInitializationService::WORLD_PACK_VERSION` goes 13 -> 14 in the same change to
 *   invalidate those. Measured on dev before the change: 9,784 staff at ability 4-75 (mean 39)
 *   and 5,278 scouts, all pool rows.
 * - **It is idempotent.** The seed only writes a row whose `staff_ability_ranges` is still
 *   empty, so a partially-applied run can be repeated without overwriting authored figures.
 */
final class Version20261120150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A25: add pool_config.staff_ability_ranges and seed it per country from each country\'s authored player band.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE pool_config ADD staff_ability_ranges JSON DEFAULT '{}' NOT NULL");

        // The seed. One statement, in SQL rather than PHP, so the arithmetic that lands in the
        // column is the arithmetic a reader of this migration can see — and so it cannot drift
        // from `StaffAbilityRangeService::bandFromPlayerBand()`, which is pinned against these
        // same figures by StaffAbilityRangeServiceTest.
        //
        // `LEAST`/`GREATEST` on the pair rather than trusting it: `league_ability_ranges` is a
        // hand-editable admin grid and a transposed row is a real state of it. A tier whose max
        // is absent or non-positive is skipped entirely, so it stays UNAUTHORED and falls
        // through to `DEFAULT_TIER_BANDS` at read time — writing a zero band instead would
        // generate ability-0 staff rather than fail.
        $this->addSql(<<<'SQL'
            UPDATE pool_config p
            SET staff_ability_ranges = COALESCE(
                (
                    SELECT jsonb_object_agg(d.country, d.band)::json
                    FROM (
                        SELECT src.country,
                               jsonb_object_agg(
                                   src.tier,
                                   jsonb_build_object(
                                       'min', GREATEST(1, LEAST(100, ROUND(0.45 * src.mid)))::int,
                                       'max', GREATEST(1, LEAST(100, ROUND(0.85 * src.mid)))::int
                                   )
                               ) AS band
                        FROM (
                            SELECT ctry.key AS country,
                                   tr.key   AS tier,
                                   (
                                       LEAST(   (tr.value->>'min')::numeric, (tr.value->>'max')::numeric)
                                     + GREATEST((tr.value->>'min')::numeric, (tr.value->>'max')::numeric)
                                   ) / 2 AS mid
                            FROM starter_config sc
                            CROSS JOIN LATERAL jsonb_each(sc.league_ability_ranges::jsonb) AS ctry(key, value)
                            CROSS JOIN LATERAL jsonb_each(ctry.value)                      AS tr(key, value)
                            WHERE sc.id = (SELECT id FROM starter_config ORDER BY id LIMIT 1)
                              AND jsonb_typeof(ctry.value) = 'object'
                              AND jsonb_typeof(tr.value)   = 'object'
                              AND (tr.value->>'max') IS NOT NULL
                              AND (tr.value->>'min') IS NOT NULL
                              AND (tr.value->>'max')::numeric > 0
                        ) src
                        GROUP BY src.country
                    ) d
                ),
                '{}'::json
            )
            WHERE p.id = (SELECT id FROM pool_config ORDER BY id LIMIT 1)
              AND COALESCE(p.staff_ability_ranges::jsonb, '{}'::jsonb) = '{}'::jsonb
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Dropping the column is a true reversal: nothing else was changed, and a down-migrated
        // database falls back to `coach_ability_min/max` for every staff member exactly as it
        // did before — which is the defect, but it is the state this migration was applied to.
        $this->addSql('ALTER TABLE pool_config DROP staff_ability_ranges');
    }
}
