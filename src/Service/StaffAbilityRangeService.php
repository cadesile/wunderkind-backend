<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PoolConfig;
use App\Repository\PoolConfigRepository;
use App\Repository\StarterConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The authored per-country, per-tier ability band a STAFF MEMBER is generated from, and the
 * one place it is resolved.
 *
 * ── WHY THIS EXISTS (A25, owner ruling 2026-11-20) ───────────────────────────────────────
 *
 * Staff ability came from TWO FLAT GLOBAL INTEGERS — `PoolConfig::$coachAbilityMin/Max`,
 * documented as 40-75 in the entity and **4-75 on the live dev row**. One pair of numbers for
 * every country and every tier in the whole game, against a table that has always been
 * authored per country and per tier for players. The pair spans 4 to 75, so it was not a band
 * at all: a bottom-tier club could be handed a 75 coach and a top-tier club a 4.
 *
 * The workaround made it worse rather than better. `StarterPackService` and the two world-pack
 * generators passed the squad's PLAYER band into `generateStaff()`/`generateScouts()`, so a
 * club's staff were drawn from the player table — and that table is **POTENTIAL**. A player
 * generated at potential 40 performs far below it; a staff member has no current/potential
 * split, so an ability-40 coach is a 40 from day one. Both sides of every staff-vs-player
 * comparison were therefore different quantities.
 *
 * ── WHAT A NUMBER IN THIS TABLE MEANS (ruling limb 2, settled, do not re-litigate) ───────
 *
 * **A staff ability is on the player `current_ability` scale.** Not potential, and not a
 * scale of its own. A staff member has no development curve, so the figure is what they
 * deliver now, and it is directly comparable to the `currentAbility` of the players they work
 * with. That is what makes the seed below derivable rather than invented.
 *
 * ── THE SEED, AND WHY IT IS A FRACTION OF THE PLAYER BAND ────────────────────────────────
 *
 * `bandFromPlayerBand()` maps a tier's authored PLAYER band onto the current-ability scale:
 * `[0.45 x midpoint, 0.85 x midpoint]`. Those two fractions are MEASURED, not chosen. Against
 * the live dev configuration (`player_first_team_age_min` 19, `player_age_max` 33,
 * `player_potential_*` 3/7/12) 1,200 generated players per tier land at a current ability of
 * almost exactly `0.6 x` the midpoint of their potential band, with the 25th-95th percentile
 * spanning `0.45x`-`0.85x`:
 *
 *   tier  authored potential  CA p25/p50/p95   0.45x-0.85x
 *   1     40-90               29 / 37 / 54     29-55
 *   4     15-35               11 / 15 / 23     11-21
 *   8     2-7                  2 /  3 /  5      2-4
 *
 * It holds to within a point or two at every live tier. ⚠️ **It is a FIT, not a law.** Against
 * the COMMITTED entity default band (75-95 at tier 1) it runs about 10% high at tiers 1-3,
 * because current ability compresses against high potential — `PlayerGenerationService`'s
 * `abilityTarget` is a fraction of potential, so the taller the potential the further below it
 * current ability sits. The full measurement is in
 * `wunderkind-docs/economy-loose-ends/a25-ability-bands-report.md`.
 *
 * So this is a **SEED an operator edits**, not a verdict on what a tier's staff should be.
 * Choosing those numbers is calibration, and calibration is deliberately out of A25's scope:
 * what A25 delivers is the authoring surface, the scale definition, and a default that is
 * coherent with the players it sits beside instead of a flat global that was neither.
 *
 * ── PRECEDENCE, AND WHY THE FLAT GLOBALS ARE NOT DEAD ────────────────────────────────────
 *
 * `resolve()` goes: the authored table, then `DEFAULT_TIER_BANDS`, then the untiered pair.
 * **`PoolConfig::$coachAbilityMin/Max` survives as the UNTIERED band and nothing else** — the
 * shared hire-market pool, which is one row served to every country at every tier and so has
 * no tier to be banded against. It was narrowed, not retired, for exactly the reason
 * `PoolConfigController` records for `scoutWageMin/Max` under A24: a brief predicted that pair
 * would become dead config and it did not, because the pool has no tier. Retiring these two
 * would have forced an untiered band to be invented somewhere else.
 *
 * ── DEPTH: SCOTLAND HAS FOUR TIERS AND MUST NOT CARRY EIGHT ROWS ─────────────────────────
 *
 * Mirrors `StarterAbilityRangeService` bullet for bullet, and for the same reasons: a country
 * gains a band it lacks (`seedDefaults()`, merge-if-absent, never overwrite) and the band
 * tracks the pyramid's DEPTH automatically while its VALUES stay hand-authored
 * (`syncToPyramid()`). Depth is taken from the country's own authored PLAYER band rather than
 * from a club or league count, because that table is already kept in step with the pyramid by
 * `StarterAbilityRangeService::syncToPyramid()` and is already what the app's country picker
 * counts to display a country's depth. On the LIVE row that gives SC four tiers and the other
 * 25 countries eight, with no dead rows anywhere.
 */
final class StaffAbilityRangeService
{
    /** The deepest pyramid the per-tier tables describe. Mirrors LeagueService::MAX_TIERS. */
    public const MAX_TIERS = 8;

    /**
     * The fallback band when a country/tier is unauthored — `DEFAULT_TIER_BANDS` applied to
     * `StarterConfig::DEFAULT_TIER_RANGES` by the measured transform above, so the two
     * committed defaults agree by construction.
     *
     * Plays the same role for staff that `WorldInitializationService::ABILITY_RANGES` plays
     * for players: generation never fails for want of a row, and the shortfall shows up in
     * `resolve()`'s `source` rather than as a silent zero band.
     */
    public const DEFAULT_TIER_BANDS = [
        1 => ['min' => 38, 'max' => 72],
        2 => ['min' => 34, 'max' => 64],
        3 => ['min' => 29, 'max' => 55],
        4 => ['min' => 25, 'max' => 47],
        5 => ['min' => 20, 'max' => 38],
        6 => ['min' => 16, 'max' => 30],
        7 => ['min' => 11, 'max' => 21],
        8 => ['min' => 8,  'max' => 15],
    ];

    public function __construct(
        private readonly PoolConfigRepository $poolConfigRepository,
        private readonly StarterConfigRepository $starterConfigRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * The band `($country, $tier)` generates staff from, and WHICH source supplied it.
     *
     * Public and source-reporting for the same reason `StarterPackService::resolveAbilityRange()`
     * is: an operator-facing diagnostic that re-derived this would be free to drift from the
     * code it is checking.
     *
     * `$tier` 0 (or an empty `$country`) is NOT an error — it is the shared pool saying it has
     * no tier, which is every `MarketPoolService` caller. It resolves to the untiered pair.
     *
     * @return array{min: int, max: int, source: string}
     */
    public function resolve(string $country, int $tier): array
    {
        $cfg  = $this->poolConfigRepository->getConfig();
        $code = strtoupper(trim($country));

        if ($code !== '' && $tier > 0) {
            $authored = $cfg->getStaffAbilityRanges()[$code][(string) $tier] ?? null;
            if (self::isUsableBand($authored)) {
                return self::normalise((int) $authored['min'], (int) $authored['max'], 'poolConfig.staffAbilityRanges');
            }

            if (isset(self::DEFAULT_TIER_BANDS[$tier])) {
                $raw = self::DEFAULT_TIER_BANDS[$tier];
                return self::normalise($raw['min'], $raw['max'], 'StaffAbilityRangeService::DEFAULT_TIER_BANDS');
            }
        }

        return self::normalise(
            $cfg->getCoachAbilityMin(),
            $cfg->getCoachAbilityMax(),
            'poolConfig.coachAbilityMin/Max (untiered)',
        );
    }

    /** Whether this country already has a usable staff band — the test `seedDefaults()` makes. */
    public function hasEntry(string $countryCode): bool
    {
        $ranges = $this->poolConfigRepository->getConfig()->getStaffAbilityRanges();
        $code   = strtoupper(trim($countryCode));

        return isset($ranges[$code]) && \is_array($ranges[$code]) && $ranges[$code] !== [];
    }

    /**
     * Seed this country's staff band from its authored PLAYER band, if it has none.
     *
     * MERGE-IF-ABSENT, NEVER OVERWRITE, exactly as `StarterAbilityRangeService::seedDefaults()`
     * promises: a country that already has a band has had it tuned, and re-seeding over the top
     * would undo that silently. Returns whether it wrote, so a caller can tell "done" from
     * "there was nothing to do".
     *
     * @return bool true when a band was written, false when one was already there
     */
    public function seedDefaults(string $countryCode): bool
    {
        $code = strtoupper(trim($countryCode));

        if ($code === '' || $this->hasEntry($code)) {
            return false;
        }

        $band = $this->seedBandFor($code, $this->authoredDepth($code));
        if ($band === []) {
            return false;
        }

        $this->write($code, $band);

        return true;
    }

    /**
     * Make this country's staff band cover exactly tiers 1..$tiers.
     *
     * GAP-FILL UPWARDS, TRIM DOWNWARDS — the same two halves, and the same trade, as
     * `StarterAbilityRangeService::syncToPyramid()`: a tier already present keeps its figures
     * whatever they are, an absent or unusable tier is written from the seed, and a tier deeper
     * than the pyramid is dropped because a band left behind by a shrunk pyramid does not read
     * as harmless leftover data — it reads as a division that still exists.
     *
     * @return array{added: int[], removed: int[]} the tiers written and the tiers dropped
     */
    public function syncToPyramid(string $countryCode, int $tiers): array
    {
        $code  = strtoupper(trim($countryCode));
        $depth = max(1, min(self::MAX_TIERS, $tiers));

        if ($code === '') {
            return ['added' => [], 'removed' => []];
        }

        $ranges = $this->poolConfigRepository->getConfig()->getStaffAbilityRanges();
        $band   = \is_array($ranges[$code] ?? null) ? $ranges[$code] : [];
        $seed   = $this->seedBandFor($code, $depth);

        $added   = [];
        $removed = [];

        for ($tier = 1; $tier <= $depth; $tier++) {
            if (self::isUsableBand($band[(string) $tier] ?? null)) {
                continue;
            }
            // A band of zeros would generate a world of ability-0 staff rather than fail, so
            // the fallback is not left to chance — same reasoning as the player twin's `??`.
            $band[(string) $tier] = $seed[(string) $tier]
                ?? self::DEFAULT_TIER_BANDS[$tier]
                ?? ['min' => 8, 'max' => 15];
            $added[]              = $tier;
        }

        foreach (array_keys($band) as $key) {
            if ((int) $key > $depth) {
                unset($band[$key]);
                $removed[] = (int) $key;
            }
        }

        if ($added === [] && $removed === []) {
            return ['added' => [], 'removed' => []];
        }

        $this->write($code, $band);

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * A tier's player POTENTIAL band mapped onto the staff (= player current ability) scale.
     *
     * The measured `0.45x`-`0.85x` of the band's midpoint; see this class's docblock for the
     * measurement and for why it is a seed rather than a law. Clamped into 1-100 and ordered,
     * because the player band it reads is a hand-editable admin grid and a transposed or
     * zeroed pair is a real state of that row.
     *
     * @param array{min: int|string, max: int|string} $playerBand
     * @return array{min: int, max: int}
     */
    public static function bandFromPlayerBand(array $playerBand): array
    {
        $lo = max(0, (int) ($playerBand['min'] ?? 0));
        $hi = max(0, (int) ($playerBand['max'] ?? 0));
        if ($lo > $hi) {
            [$lo, $hi] = [$hi, $lo];
        }

        $mid = ($lo + $hi) / 2;

        $min = max(1, min(100, (int) round($mid * 0.45)));
        $max = max(1, min(100, (int) round($mid * 0.85)));

        return ['min' => min($min, $max), 'max' => max($min, $max)];
    }

    /**
     * How many tiers this country's authored PLAYER band describes, which is this project's
     * standing proxy for the pyramid's depth — see the class docblock. Falls back to the full
     * eight when the country has no player band either, because that is what every other
     * un-authored country is given.
     */
    private function authoredDepth(string $code): int
    {
        $playerRanges = $this->starterConfigRepository->getConfig()->getLeagueAbilityRanges();
        $band         = $playerRanges[$code] ?? null;

        if (!\is_array($band) || $band === []) {
            return self::MAX_TIERS;
        }

        $depth = 0;
        foreach (array_keys($band) as $key) {
            $depth = max($depth, (int) $key);
        }

        return max(1, min(self::MAX_TIERS, $depth));
    }

    /**
     * The seeded band for tiers 1..$depth, each tier derived from that country's own authored
     * player band where it has one and from the committed default where it does not.
     *
     * @return array<string, array{min: int, max: int}>
     */
    private function seedBandFor(string $code, int $depth): array
    {
        $playerRanges = $this->starterConfigRepository->getConfig()->getLeagueAbilityRanges();
        $playerBand   = \is_array($playerRanges[$code] ?? null) ? $playerRanges[$code] : [];

        $seed = [];
        for ($tier = 1; $tier <= $depth; $tier++) {
            $row = $playerBand[(string) $tier] ?? null;
            $seed[(string) $tier] = \is_array($row) && ((int) ($row['max'] ?? 0)) > 0
                ? self::bandFromPlayerBand($row)
                : (self::DEFAULT_TIER_BANDS[$tier] ?? ['min' => 8, 'max' => 15]);
        }

        return $seed;
    }

    /** @param array<string, array{min: int, max: int}> $band */
    private function write(string $code, array $band): void
    {
        $config = $this->poolConfigRepository->getConfig();

        // getConfig() hands back an UNMANAGED PoolConfig when no row exists yet, and flushing
        // one of those writes nothing at all. Persisting first is what makes this work on a
        // database that has never had the row — the state a fresh environment is in, and
        // exactly when an operator is bootstrapping their first country. Same note as
        // StarterAbilityRangeService.
        if (!$this->em->contains($config)) {
            $this->em->persist($config);
        }

        // Keyed by tier as a STRING throughout, because that is what the player column already
        // holds and what every reader indexes it by. Sorted on the numeric value so the admin
        // grid and any export read 1..8 rather than 1,10,2.
        uksort($band, static fn ($a, $b): int => (int) $a <=> (int) $b);

        $ranges        = $config->getStaffAbilityRanges();
        $ranges[$code] = $band;
        $config->setStaffAbilityRanges($ranges);

        $this->em->flush();
    }

    /** A stored band is usable only if it is a real pair with a positive floor. */
    private static function isUsableBand(mixed $value): bool
    {
        return \is_array($value)
            && (int) ($value['min'] ?? 0) > 0
            && (int) ($value['max'] ?? 0) > 0;
    }

    /** @return array{min: int, max: int, source: string} */
    private static function normalise(int $min, int $max, string $source): array
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return ['min' => $min, 'max' => $max, 'source' => $source];
    }
}
