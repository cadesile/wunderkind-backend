<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Author the prize and position-pot ladders, and let every league inherit them.
 *
 * ⚠️ THIS ONE MOVES PUBLISHED MONEY, DELIBERATELY. `Version20261116180000` added the columns and
 * could not change a figure; this is the re-pricing. It is one transaction so a box is never
 * half-authored, and it stashes everything it overwrites so `down()` is exact rather than
 * approximate.
 *
 * ── WHAT IT AUTHORS ───────────────────────────────────────────────────────────────────────
 *
 * Both bases stay at the tier-1 figure the world already had: £100,000,000, which is 0.2573x
 * tier 1's authored turnover for each of the two ladders. Tier 1 is correct today and does not
 * move. Both ratio tables become the turnover ladder normalised to tier 1, to three significant
 * figures so they read as authored numbers rather than derived ones — the same convention the TV
 * table uses:
 *
 *   [1000000, 309000, 91500, 51200, 18200, 6020, 1500, 424]
 *
 * which puts every tier within 0.05% of a flat 0.2573x of ITS OWN authored turnover:
 *
 *   tier   turnover        prize & pot      x own turnover
 *   1      £388,661,540    £100,000,000     0.2573
 *   2      £120,049,757    £30,900,000      0.2574
 *   3      £35,576,602     £9,150,000       0.2572
 *   4      £19,909,009     £5,120,000       0.2572
 *   5      £7,071,796      £1,820,000       0.2574
 *   6      £2,339,899      £602,000         0.2573
 *   7      £582,785        £150,000         0.2574
 *   8      £164,852        £42,400          0.2572
 *
 * The EN tier-8 division's total payout (TV pool + pot x 5.5 + prize) goes from £16,089,100
 * against £2,967,328 of combined authored turnover — 5.42x — to £364,700, or 0.12x. A champion's
 * own take (prize + first-place pot) goes from 42.46x its turnover to 0.5144x, and lands between
 * 0.5143x and 0.5148x at EVERY tier — the spread is three-significant-figure rounding in the
 * ratios, nothing else.
 *
 * ── WHY THE PER-LEAGUE COLUMNS ARE CLEARED ────────────────────────────────────────────────
 *
 * `LeaguePayoutResolver` treats a non-null column as a hand-authored OVERRIDE and returns it
 * verbatim, so the ladder would be dead on arrival while every league holds one — and all 196
 * do. Measured before writing this: all 24 eight-tier countries held a BYTE-IDENTICAL prize/pot
 * ladder and SC held rows 1-4 of the same one, so there is not a single genuine per-league
 * override anywhere in the world. Nothing is lost by clearing them; an override from here on is
 * a deliberate act through the per-league quick editor.
 *
 * `league_tier_defaults`' own prize/pot entries are cleared for the same reason, and it is the
 * subtler half: that row is what `LeagueService::generateLeaguesForCountry()` writes onto a
 * brand-new League. Left populated, the next country an operator adds would be born holding
 * eight overrides and would silently opt out of the ladder — the ratio tables would be authored,
 * correct, and ignored for that country only.
 *
 * ── REVERSIBILITY ─────────────────────────────────────────────────────────────────────────
 *
 * A null does not record what it replaced, so `down()` cannot reconstruct these figures from the
 * schema. `up()` therefore writes `league_payout_pre_ratio_backup` — one row per league plus one
 * row holding the old `league_tier_defaults` JSON and the four config figures — and `down()`
 * restores from it and drops it. That makes the rollback exact for the authored England ladder
 * rather than resetting to the shipped constants, which differ from it at every tier.
 *
 * ── AND THE CACHE ─────────────────────────────────────────────────────────────────────────
 *
 * Both figures are baked into the cached league block, so `WORLD_PACK_VERSION` is bumped to 7 in
 * the same release (see `WorldInitializationService`). Without it these packs would keep serving
 * the inverted ladder with nothing about the payload looking different — which is exactly what
 * happened to `tv_deal` on 2026-11-13, 60 packs silently 25x out of date.
 */
final class Version20261116181000 extends AbstractMigration
{
    /** Tier 1 is unchanged; the rest is the turnover ladder normalised to it, to 3 s.f. */
    private const RATIO_PPM = '[1000000,309000,91500,51200,18200,6020,1500,424]';

    /** £100,000,000 in pence — tier 1's existing figure, for both ladders. */
    private const TIER1_PENCE = 10000000000;

    public function getDescription(): string
    {
        return 'Author the prize and position-pot ratio ladders at 0.2573x of each tier\'s own turnover, clear the per-league and tier-default figures they supersede, and stash what was replaced.';
    }

    public function up(Schema $schema): void
    {
        // THE STASH FIRST, so nothing is cleared before it is recorded. `scope` is 'league' for
        // a league row and 'config' for the single game_config row; `league_id` is null on the
        // latter. Plain columns rather than one JSON blob, so a human can read the old ladder
        // out of it with a SELECT if the rollback is ever needed under pressure.
        $this->addSql(<<<'SQL'
            CREATE TABLE league_payout_pre_ratio_backup (
                id                  SERIAL PRIMARY KEY,
                scope               VARCHAR(16)  NOT NULL,
                league_id           UUID         DEFAULT NULL,
                prize_money         BIGINT       DEFAULT NULL,
                league_position_pot BIGINT       DEFAULT NULL,
                tier_defaults       JSON         DEFAULT NULL,
                captured_at         TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO league_payout_pre_ratio_backup (scope, league_id, prize_money, league_position_pot)
            SELECT 'league', id, prize_money, league_position_pot FROM league
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO league_payout_pre_ratio_backup (scope, tier_defaults, prize_money, league_position_pot)
            SELECT 'config',
                   league_tier_defaults,
                   economy_league_prize_tier1_pence,
                   economy_league_position_pot_tier1_pence
            FROM game_config
            SQL);

        // NOW THE LADDER. Authored before the columns are cleared, so there is no instant inside
        // the transaction at which a league resolves to "unauthored".
        $this->addSql(sprintf(
            "UPDATE game_config SET economy_prize_tier_ratio_ppm = '%s', economy_position_pot_tier_ratio_ppm = '%s', economy_league_prize_tier1_pence = %d, economy_league_position_pot_tier1_pence = %d",
            self::RATIO_PPM,
            self::RATIO_PPM,
            self::TIER1_PENCE,
            self::TIER1_PENCE,
        ));

        // Clear the two tier-default entries, key by key, so every other key in the row
        // (promotion spots, TV, sponsor count, trophy, parachute) survives untouched —
        // overwriting the whole row would silently drop a trophy or a parachute schedule.
        //
        // ⚠️ `jsonb_exists(col, key)` RATHER THAN THE `?` OPERATOR. DBAL parses `?` in a
        // migration's SQL as a positional parameter placeholder and fails with
        // "syntax error at or near $1" before Postgres ever sees it. The function form is the
        // same test. The guard matters: for a tier absent from the row, `-> 'N'` is NULL and
        // `NULL || '{...}'` is NULL, which would blank the row rather than leave it alone.
        for ($tier = 1; $tier <= 8; $tier++) {
            $this->addSql(sprintf(
                "UPDATE game_config SET league_tier_defaults = (league_tier_defaults::jsonb || jsonb_build_object('%d', (league_tier_defaults::jsonb -> '%d') || '{\"prizeMoney\": null, \"leaguePositionPot\": null}'::jsonb))::json WHERE jsonb_exists(league_tier_defaults::jsonb, '%d')",
                $tier,
                $tier,
                $tier,
            ));
        }

        // AND THE LEAGUES. Unconditional, because the stash above makes the rollback exact and a
        // `WHERE value = tier default` guard would leave behind exactly the rows whose tier
        // default had already drifted — the ones most in need of being brought onto the ladder.
        $this->addSql('UPDATE league SET prize_money = NULL, league_position_pot = NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE league l
               SET prize_money         = b.prize_money,
                   league_position_pot = b.league_position_pot
              FROM league_payout_pre_ratio_backup b
             WHERE b.scope = 'league' AND b.league_id = l.id
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE game_config g
               SET league_tier_defaults                    = b.tier_defaults,
                   economy_league_prize_tier1_pence        = COALESCE(b.prize_money, 0),
                   economy_league_position_pot_tier1_pence = COALESCE(b.league_position_pot, 0)
              FROM (SELECT * FROM league_payout_pre_ratio_backup WHERE scope = 'config' ORDER BY id LIMIT 1) b
             WHERE TRUE
            SQL);

        // Back to parity, so a re-run of up() is not comparing against a half-authored ladder.
        $this->addSql("UPDATE game_config SET economy_prize_tier_ratio_ppm = '[1000000,1000000,1000000,1000000,1000000,1000000,1000000,1000000]', economy_position_pot_tier_ratio_ppm = '[1000000,1000000,1000000,1000000,1000000,1000000,1000000,1000000]'");

        $this->addSql('DROP TABLE league_payout_pre_ratio_backup');
    }
}
