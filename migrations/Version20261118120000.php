<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Author the re-derived economy (resolution R2) — turnover, both payout ladders, and the
 * bottom-band facility set.
 *
 * ⚠️ THIS ONE MOVES PUBLISHED MONEY, DELIBERATELY, and it moves EVERY COUNTRY. It follows
 * `Version20261116181000`'s shape exactly: one transaction so a box is never half-authored, and
 * everything it overwrites is stashed first so `down()` is exact rather than approximate.
 *
 * ── WHY ───────────────────────────────────────────────────────────────────────────────────
 *
 * The economy overhaul solved the authored turnover table against a gate numerator that was
 * wrong in two directions, and the prize-ladder fix (`5dc3f51b`, 2026-11-17) then removed a
 * large income line from the bottom of the pyramid. Measured 2026-11-18: the app's variant gate
 * goes PASS -> FAIL, tier 8 is insolvent in season 1, and tiers 2 and 3 drop out of the 85-110%
 * COVERAGE band they were inside. The dials were re-derived against the corrected gate and the
 * owner chose R2: the ladder that satisfies BOTH this repo's `app:economy:wage-table` checks and
 * as much of the app's coverage band as is reachable without re-authoring the ability bands.
 *
 * Against the previous authoring it brings six tiers into band from four, tier 8 from 535%
 * COVERAGE to 162%, and tier 6 from 192% to 137%. Tiers 6 and 8 sitting OUTSIDE the band is the
 * accepted outcome of R2, not a defect: closing them is a squad-quality change (re-authoring the
 * bottom ability bands), which is a BALANCE decision and deliberately out of scope here.
 *
 * ── 1. THE TURNOVER TABLE, AND WHOSE IT IS ────────────────────────────────────────────────
 *
 * ⚠️ `economy_baseline_turnover_pence` is the BASELINE COUNTRY's table, not England's. Every
 * other country is this row times its `country_profile.economy_factor_bp`, all of which are at
 * parity today — so authoring it moves every country at once, while only EN and the shallow
 * comparison country have been gated.
 *
 *   tier   authored turnover    x previous
 *   1      £331,000,000         0.85
 *   2      £114,000,000         0.95
 *   3      £34,600,000          0.97
 *   4      £20,700,000          1.04
 *   5      £7,320,000           1.04
 *   6      £2,690,000           1.15
 *   7      £686,000             1.18
 *   8      £215,000             1.30
 *
 * ⚠️ THESE VALUES ARE NOT FREE TO ROUND. Each is the largest figure that keeps `S_tier`
 * strictly decreasing (`app:economy:wage-table` check 1) given the tier above it, and each is
 * already rounded DOWN to three significant figures so the chain survives rounding. Rounding
 * them again, or tidying one upward, breaks check 1 at that tier.
 *
 * ── 2. NOTHING IS AUTHORED FOR WAGES, DELIBERATELY ────────────────────────────────────────
 *
 * `EconomyScaleResolver::wageScaleMicroPence()` DERIVES the scale from the turnover row
 * (`S_tier = playerBudget / (weeksPerSeason x sum(ability^k))`), and the youth scholarship
 * anchor follows the average wage, so step 1 above moves both with no second authoring action.
 * Writing either by hand is what makes the chain disagree with itself.
 *
 * Against the live reference squad row the published scale comes out as
 *
 *   332484503 . 331618346 . 303799192 . 290519761 . 258711870 . 257021938 . 235402484 . 233303081
 *
 * strictly decreasing, which is what makes check 1 pass. Staff and scout pay do NOT move:
 * `TierBandScaleResolver` reads the ability bands, not turnover.
 *
 * ── 3. BOTH PAYOUT LADDERS, RE-DERIVED ONTO THE NEW TURNOVER ──────────────────────────────
 *
 * The ppm ladder `Version20261116181000` authored IS the OLD turnover ladder normalised to tier
 * 1, so step 1 silently invalidates it — they move together or not at all. Both tables become
 * the NEW ladder normalised to tier 1, and both tier-1 bases become `0.2573 x T1`:
 *
 *   £85,166,300 (down from £100,000,000), ladder
 *   [1000000, 344411, 104532, 62538, 22115, 8127, 2073, 650]
 *
 * Everything `5dc3f51b` established is kept: parts per million rather than basis points (tier 8
 * is 650 ppm = 6.5 bp, and one bp step there would be 15% of the value); NULL = inherit; the
 * country factor applied once and never through `scalePence()`; and the split-the-money division
 * in `LeaguePayoutResolver::resolve()` that keeps one truncation per figure.
 *
 * The per-league columns and the `league_tier_defaults` prize/pot entries are NOT touched here:
 * `Version20261116181000` already nulled every one of them, and NULL = inherit means they pick
 * the new ladder up by themselves. Re-nulling them would stash a second, redundant backup and
 * make this migration's `down()` fight that one's.
 *
 * ── 4. THE BOTTOM-TIER GROUND AND FACILITY SET ────────────────────────────────────────────
 *
 * ⚠️ THIS STEP IS LOAD-BEARING, NOT TIDYING. Without it tier 8's upkeep is 40.7% of income
 * against a 60% wage share and the run fails on solvency; with it, 32.2%.
 *
 * `npc_facility_level_ranges` is keyed by BAND, and band 3 is tiers 7 AND 8 together —
 * `NpcClubGenerationService::getBandIndexForTier()` keys on the ABSOLUTE tier, so a country with
 * fewer than seven tiers is untouched. The column has NO country dimension, so this is a
 * statement about what a bottom-division club is, in every 8-tier country.
 *
 * What band 3 held before this migration (read from the authored column, not assumed):
 *
 *   north_stand 1-1 . south_stand 1-1 . east_stand 1-2 . west_stand 1-2  (mean total 5.0)
 *   scouting_center 1-1 . club_shop 1-1 . training_pitch 1-1 . physio_clinic 1-1
 *   youth_academy 0-1 . technical_zone 0-1 . tactical_room 0-1 . kinetic_lab 0-1
 *   museum 0-0 . strength_suite 0-0 . hydro_pool 0-0 . advanced_scouting 0-0
 *   biometric_hub 0-0 . zenith_sanctuary 0-0 . neuro_shed 0-0
 *
 * That settles the one input the derivation could not see. OVERRIDES ARE AUTHORED — the mean
 * total stand level of 5.0 is what the Nov-15 world export measured (4.89-5.05) against a code
 * default of `0-1` per stand that would give 2.0, so the discrepancy was never the NPC upgrade
 * drift `game-mechanics.md` 13.3 describes. The stand half of this step is therefore a REAL
 * change, not a no-op: the four stands drop from a mean total of 5.0 to 2.0.
 *
 * ⚠️ `create_missing = true` ON EVERY `jsonb_set`, AND THAT IS THE SUBTLE HALF. Six of the nine
 * excluded slugs (`scout_fleet`, `imaging_suite`, `player_wellbeing`, `nutrition_centre`,
 * `trial_pitch`, `car_park`) have NO entry in band 3 today, and `buildFacilities()` falls back to
 * `FACILITY_LEVELS` band 3's `other` range of `0-1` for a slug it finds no override for. Writing
 * them as `0-0` is what actually excludes them; omitting them would leave all six still built.
 *
 * After this, the active facility templates leave a bottom-division club exactly EIGHT
 * facilities: four small stands, a training pitch, a physio clinic, a club shop and a tactical
 * room. (`kinetic_lab`, `zenith_sanctuary` and `neuro_shed` are keys with no matching active
 * `facility_template` row — inert, and left exactly as found rather than quietly cleaned up.)
 *
 * ── 5. ONE addSql PER KEY ─────────────────────────────────────────────────────────────────
 *
 * The facility column is written one SLUG at a time with `jsonb_set`, never as a whole-row or
 * whole-band overwrite, for the reason `Version20261116181000` gives about `league_tier_defaults`:
 * a whole-row write silently drops every key the writer did not think to carry forward. Here that
 * would be the other three bands and the eleven band-3 slugs this migration does not move.
 *
 * ── 6. REVERSIBILITY ──────────────────────────────────────────────────────────────────────
 *
 * `up()` writes `economy_r2_pre_authoring_backup` — ONE row holding all six figures as they were
 * — before it writes anything, and `down()` restores from it and drops the table. That makes the
 * rollback exact for whatever the box was actually holding, rather than resetting to the shipped
 * constants, which differ from the authored values at every tier.
 *
 * ── 7. AND THE CACHE ──────────────────────────────────────────────────────────────────────
 *
 * Packs bake facilities, wages, `tvDeal` and both payout figures, so
 * `WorldInitializationService::WORLD_PACK_VERSION` goes 9 -> 10 in the same release. Existing NPC
 * clubs also keep the facilities they already have, so step 4 reaches nothing until the bottom
 * two tiers are REGENERATED — the bump and `app:worldpack:maintain` are what restock after that.
 * A save already in progress keeps its own world and is reached by none of this.
 */
final class Version20261118120000 extends AbstractMigration
{
    /** R2's chain-maximal ladder, in pence. Baseline country; every other is this x its factor. */
    private const TURNOVER_PENCE = '[33100000000,11400000000,3460000000,2070000000,732000000,269000000,68600000,21500000]';

    /** The new turnover ladder normalised to tier 1, parts per million. Both tables. */
    private const RATIO_PPM = '[1000000,344411,104532,62538,22115,8127,2073,650]';

    /** £85,166,300 in pence = 0.2573 x tier 1's new turnover. Both bases. */
    private const TIER1_PENCE = 8516630000;

    /** Band 3 is tiers 7 AND 8. A 2,500-seat ground at `capacityCalculation` 1250. */
    private const BAND_INDEX = 3;

    /** @var list<string> */
    private const STAND_SLUGS = ['north_stand', 'south_stand', 'east_stand', 'west_stand'];

    /**
     * `min 0, max 0`, which `buildFacilities()` reads as "this band does not hold it" and skips.
     *
     * @var list<string>
     */
    private const EXCLUDED_SLUGS = [
        'scouting_center',
        'scout_fleet',
        'imaging_suite',
        'player_wellbeing',
        'nutrition_centre',
        'technical_zone',
        'youth_academy',
        'trial_pitch',
        'car_park',
    ];

    public function getDescription(): string
    {
        return 'Author resolution R2: the re-derived baseline turnover ladder, both payout ratio tables and tier-1 bases re-derived onto it, and band 3 of npcFacilityLevelRanges cut to a bottom-division ground of eight facilities — stashing everything replaced.';
    }

    public function up(Schema $schema): void
    {
        // THE STASH FIRST, so nothing is overwritten before it is recorded. One row, plain
        // columns rather than a single blob, so a human can read the old ladder out of it with a
        // SELECT if the rollback is ever needed under pressure.
        $this->addSql(<<<'SQL'
            CREATE TABLE economy_r2_pre_authoring_backup (
                id                                      SERIAL PRIMARY KEY,
                baseline_turnover_pence                 JSON   DEFAULT NULL,
                prize_tier_ratio_ppm                    JSON   DEFAULT NULL,
                position_pot_tier_ratio_ppm             JSON   DEFAULT NULL,
                league_prize_tier1_pence                BIGINT DEFAULT NULL,
                league_position_pot_tier1_pence         BIGINT DEFAULT NULL,
                npc_facility_level_ranges               JSON   DEFAULT NULL,
                captured_at                             TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO economy_r2_pre_authoring_backup (
                baseline_turnover_pence,
                prize_tier_ratio_ppm,
                position_pot_tier_ratio_ppm,
                league_prize_tier1_pence,
                league_position_pot_tier1_pence,
                npc_facility_level_ranges
            )
            SELECT economy_baseline_turnover_pence,
                   economy_prize_tier_ratio_ppm,
                   economy_position_pot_tier_ratio_ppm,
                   economy_league_prize_tier1_pence,
                   economy_league_position_pot_tier1_pence,
                   npc_facility_level_ranges
              FROM game_config
            SQL);

        // ── STEP 1. The turnover table. ──────────────────────────────────────────────────
        $this->addSql(sprintf(
            "UPDATE game_config SET economy_baseline_turnover_pence = '%s'",
            self::TURNOVER_PENCE,
        ));

        // ── STEP 3. Both ladders, re-derived onto it. One addSql per key. ────────────────
        $this->addSql(sprintf(
            "UPDATE game_config SET economy_prize_tier_ratio_ppm = '%s'",
            self::RATIO_PPM,
        ));

        $this->addSql(sprintf(
            "UPDATE game_config SET economy_position_pot_tier_ratio_ppm = '%s'",
            self::RATIO_PPM,
        ));

        $this->addSql(sprintf(
            'UPDATE game_config SET economy_league_prize_tier1_pence = %d',
            self::TIER1_PENCE,
        ));

        $this->addSql(sprintf(
            'UPDATE game_config SET economy_league_position_pot_tier1_pence = %d',
            self::TIER1_PENCE,
        ));

        // ── STEP 4. Band 3's ground and facility set, ONE SLUG PER STATEMENT. ────────────
        //
        // `create_missing = true` (the trailing TRUE) matters as much as the value: a slug with
        // no band-3 entry falls back to FACILITY_LEVELS' `other` range of 0-1 and would still be
        // built. Six of the nine exclusions are in exactly that state today.
        foreach (self::STAND_SLUGS as $slug) {
            $this->addSql(sprintf(
                "UPDATE game_config SET npc_facility_level_ranges = jsonb_set(npc_facility_level_ranges::jsonb, '{%d,%s}', '{\"min\": 0, \"max\": 1}'::jsonb, TRUE)::json",
                self::BAND_INDEX,
                $slug,
            ));
        }

        foreach (self::EXCLUDED_SLUGS as $slug) {
            $this->addSql(sprintf(
                "UPDATE game_config SET npc_facility_level_ranges = jsonb_set(npc_facility_level_ranges::jsonb, '{%d,%s}', '{\"min\": 0, \"max\": 0}'::jsonb, TRUE)::json",
                self::BAND_INDEX,
                $slug,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        // RESTORED FROM THE STASH, not from the shipped constants: the box this rolls back on
        // was holding authored figures that differ from them at every tier. COALESCE keeps a
        // rollback against a stash that was itself capturing an unauthored column honest —
        // the turnover table's own "nothing authored" state is all zeros, not NULL.
        $this->addSql(<<<'SQL'
            UPDATE game_config g
               SET economy_baseline_turnover_pence         = b.baseline_turnover_pence,
                   economy_prize_tier_ratio_ppm            = b.prize_tier_ratio_ppm,
                   economy_position_pot_tier_ratio_ppm     = b.position_pot_tier_ratio_ppm,
                   economy_league_prize_tier1_pence        = COALESCE(b.league_prize_tier1_pence, 0),
                   economy_league_position_pot_tier1_pence = COALESCE(b.league_position_pot_tier1_pence, 0),
                   npc_facility_level_ranges               = b.npc_facility_level_ranges
              FROM (SELECT * FROM economy_r2_pre_authoring_backup ORDER BY id LIMIT 1) b
             WHERE TRUE
            SQL);

        $this->addSql('DROP TABLE economy_r2_pre_authoring_backup');
    }
}
