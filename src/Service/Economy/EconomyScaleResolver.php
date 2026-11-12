<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\GameConfig;
use App\Repository\GameConfigRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\StarterConfigRepository;

/**
 * THE only place country and tier compose into a money figure.
 *
 * WHY ONE CLASS AND NOT A HELPER PER CALLER. The chain is short but it has to be identical
 * everywhere, because its inputs are stored in three different tables and its output is baked
 * into generated rows that outlive the request. Two call sites composing the same factors in a
 * different order, or rounding at a different step, produce clubs in the same division that
 * disagree about what a player costs — and nothing surfaces that except a player noticing.
 * Four copies of a tier-walk is the mistake this codebase has already made once with wage
 * multipliers (WorldPackClubGenerationService, MarketPoolService, StaffGenerationService), and
 * unifying those is what step 5 of this work exists to do.
 *
 * ── INTEGER BASIS POINTS, ONE DIVISION AT THE END ─────────────────────────────────────────
 *
 * Every factor is an integer in basis points and the chain divides exactly once, with intdiv,
 * at the point it becomes pence. No float enters it. The single exception is the ability curve,
 * which genuinely needs pow() with a fractional exponent; it is computed in ONE place
 * (wageScaleMicroPence()) and rounded ONCE, and the result is carried as pence x 10^6 so the
 * per-player multiply downstream stays integer too.
 *
 * Why micro-pence rather than pence: S_tier for a bottom division is a fraction of a penny per
 * unit of ability^k (around 2.3 pence at England tier 8 with the live bands, but a hundredth of
 * that for a shallow country at a low factor). Rounding it to pence here would quantise every
 * wage in the division to the same handful of values, and for small factors would round it to
 * zero and make every player free. Six decimal places of headroom is enough that the final
 * per-player rounding is the only one that bites.
 *
 * ── THE BASELINE COUNTRY ──────────────────────────────────────────────────────────────────
 *
 * Factors are a PERCENTAGE OF THE BASELINE, normalised against the baseline row's own stored
 * value rather than against a hardcoded 10000. An operator may reasonably set England to 12000
 * while thinking in absolute terms; the baseline must still resolve to parity with itself, and
 * every other country must still be a ratio of it. `country_profile` enforces exactly one
 * baseline with a partial unique index, so this never has to pick a winner.
 *
 * With no baseline row flagged at all — a fresh database, or before the migration's postUp has
 * run — every country resolves at parity. That is the inert state and it is deliberately not an
 * exception: a half-configured economy must degrade to "no scaling", not to a 500.
 *
 * ── RAW ROW INDEXING ──────────────────────────────────────────────────────────────────────
 *
 * Tier t reads row t. A 4-tier country uses rows 1-4 and does NOT have the eight-rung tables
 * stretched across its four divisions. That reverses an earlier convention (a since-removed
 * GameConfig::scaleTierToEight()) and the reasoning is in
 * GameConfig::getNpcClubBalanceRangeForTier()'s docblock: one link of a money chain stretching
 * while the others do not is worse than a bottom division that is merely generous.
 *
 * ── INFLATION ─────────────────────────────────────────────────────────────────────────────
 *
 * The index is computed here but applied almost nowhere. It must never reach a generated row,
 * a published money value, or a world pack — those are all base-year figures the app inflates
 * itself, and the pack cache has no season dimension to key an inflated value by. The only
 * server-side consumer is the season-investment ceiling, which has to be evaluated against the
 * season being claimed for.
 */
final class EconomyScaleResolver
{
    /** Pence x 10^6. See the class docblock on why S_tier is not rounded to pence here. */
    public const WAGE_SCALE_UNIT = 1_000_000;

    private const BP = 10_000;

    /** Matches every other tier cap in this codebase; the config tables are eight rows. */
    private const MAX_TIERS = 8;

    /**
     * Resolved factors memoised per (country, tier) for the life of the request.
     *
     * A single world-pack build resolves the same country and tier once per club, and a
     * calibration preview walks eight tiers twice. The inputs cannot change mid-request —
     * nothing here writes — so caching is safe, and it keeps the baseline lookup from being
     * one query per club.
     *
     * @var array<string, EconomyScale>
     */
    private array $cache = [];

    /** Memoised separately: it is keyed by country alone and read by tierCountFor() directly. */
    private array $tierCountCache = [];

    private ?int $baselineFactorBp = null;

    public function __construct(
        private readonly GameConfigRepository     $gameConfigRepository,
        private readonly StarterConfigRepository  $starterConfigRepository,
        private readonly CountryProfileRepository $countryProfileRepository,
    ) {}

    /**
     * How deep this country's pyramid is declared to be, 1-8.
     *
     * MOVED HERE FROM NpcClubGenerationService::tierCountFor(), which was the only lookup of it
     * in the codebase. It belongs with the rest of the per-country economy inputs so a second
     * caller cannot arrive at a different answer — and the resolver needs it anyway, to clamp a
     * requested tier to a pyramid that does not have it.
     *
     * The DECLARED INTENT, not a count of league rows: clubs are often generated while a
     * structure is half-built, and rescaling them against however many tiers exist at that
     * moment bakes in the wrong figures. Falls back to the full eight for a country with no
     * profile yet.
     */
    public function tierCountFor(string $country): int
    {
        $key = strtoupper(trim($country));

        return $this->tierCountCache[$key] ??= $this->countryProfileRepository
            ->findByCode($key)?->getLeagueTierCount() ?? self::MAX_TIERS;
    }

    /** The full breakdown for one (country, tier). */
    public function resolve(string $country, int $tier): EconomyScale
    {
        $code = strtoupper(trim($country));
        $key  = $code . ':' . $tier;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $config    = $this->gameConfigRepository->getConfig();
        $tierCount = $this->tierCountFor($code);

        // Clamped to the country's OWN depth, then to the table's eight rows. Asking a 4-tier
        // country for tier 7 is a caller bug, but answering it with a null row would surface as
        // a wage of zero rather than as an error, so it resolves to the bottom division it has.
        $effectiveTier = max(1, min(min($tierCount, self::MAX_TIERS), $tier));

        $countryFactorBp = $this->countryFactorBp($code);
        $tierFactorBp    = $config->getEconomyTierFactorsBp()[$effectiveTier - 1] ?? self::BP;

        // intdiv, not /: the product of two basis-point factors is basis points squared, and
        // this is the one place it comes back down. Floors rather than rounds, which is the
        // conservative direction for a figure that becomes a wage bill.
        $combinedBp = intdiv($countryFactorBp * $tierFactorBp, self::BP);

        $baselineTurnover = $config->getEconomyBaselineTurnoverPence()[$effectiveTier - 1] ?? 0;
        $turnoverPence    = intdiv($baselineTurnover * $countryFactorBp, self::BP);

        $scale = new EconomyScale(
            country:               $code,
            tier:                  $effectiveTier,
            countryFactorBp:       $countryFactorBp,
            tierFactorBp:          $tierFactorBp,
            combinedFactorBp:      $combinedBp,
            baselineTurnoverPence: $baselineTurnover,
            turnoverPence:         $turnoverPence,
            wageScaleMicroPence:   $this->wageScaleMicroPence($config, $code, $effectiveTier, $turnoverPence),
            tierCount:             $tierCount,
            // An unauthored turnover row is the shipped state, and it is what makes the
            // turnover wage model unreachable until an operator fills the table in.
            isResolvable:          $baselineTurnover > 0,
        );

        return $this->cache[$key] = $scale;
    }

    /**
     * This country's factor as basis points of the baseline country's.
     *
     * Normalised, so the baseline is always exactly 10000 against itself whatever it stores.
     */
    public function countryFactorBp(string $country): int
    {
        $code     = strtoupper(trim($country));
        $baseline = $this->baselineFactorBp();

        if ($baseline <= 0) {
            return self::BP;
        }

        $own = $this->countryProfileRepository->findByCode($code)?->getEconomyFactorBp() ?? self::BP;

        return intdiv($own * self::BP, $baseline);
    }

    /** Multiply a base-year pence figure by this country and tier. The chain's public entry point. */
    public function scalePence(int $basePence, string $country, int $tier): int
    {
        return intdiv($basePence * $this->resolve($country, $tier)->combinedFactorBp, self::BP);
    }

    /**
     * The compounded inflation index for a season, in basis points. 10000 = no inflation.
     *
     * Season 1 is the base year and is always exactly 10000 — the first season a club plays has
     * had no inflation applied to it yet, and anchoring the compounding at season 1 rather than
     * season 0 is what makes that true.
     *
     * COMPOUNDED IN INTEGERS, one season at a time, rather than with pow(): the result is a
     * ceiling multiplier an operator may have to reproduce by hand from the audit record, and
     * `(1 + r)^n` in floating point does not reproduce by hand. The loop is bounded by the
     * season cap below, so a save reporting season 40,000 cannot spin here.
     */
    public function inflationIndexBp(int $season): int
    {
        $rate = $this->gameConfigRepository->getConfig()->getInflationRatePerSeasonBp();

        if ($rate <= 0) {
            return self::BP;
        }

        // A season count arrives from a device-mirrored column, so it is bounded here rather
        // than trusted. 200 seasons is far beyond any real save and keeps the index finite.
        $seasons = max(1, min(200, $season)) - 1;
        $index   = self::BP;

        for ($i = 0; $i < $seasons; $i++) {
            $index = intdiv($index * (self::BP + $rate), self::BP);
        }

        return $index;
    }

    /**
     * S_tier: what one unit of ability^k costs per week, in pence x 10^6.
     *
     * Derived from a REFERENCE SQUAD rather than from a chosen number, which is the whole idea
     * of the turnover model: a tier's wage scale is whatever makes a representative squad for
     * that tier cost the configured share of that tier's turnover over a season. So the inputs
     * are the squad size and ability spread the world generator actually uses — starter-config's
     * npcSquadConfig and leagueAbilityRanges — and not a second set of numbers that could drift
     * from them.
     *
     *   playerBudget = turnover x wageToTurnoverRatio x (1 - staffShare)
     *   S_tier       = playerBudget / (weeksPerSeason x SUM(ability_i ^ k))
     *
     * The sum runs over N evenly spaced abilities across the tier's band, where N is the
     * midpoint of the tier's playerMin/playerMax. Evenly spaced rather than sampled: a
     * reference squad is a definition, and a random one would make S_tier differ between two
     * calls for no reason.
     *
     * Returns 0 when the turnover row is unauthored — the caller checks `isResolvable` rather
     * than dividing by a zero scale.
     */
    private function wageScaleMicroPence(GameConfig $config, string $country, int $tier, int $turnoverPence): int
    {
        if ($turnoverPence <= 0) {
            return 0;
        }

        $sigma = $this->referenceSquadAbilitySum($config, $country, $tier);
        $weeks = $config->getEconomyWeeksPerSeason();

        if ($sigma <= 0.0 || $weeks <= 0) {
            return 0;
        }

        $wageBudget   = intdiv($turnoverPence * $config->getEconomyWageToTurnoverRatioBp(), self::BP);
        $playerBudget = intdiv($wageBudget * (self::BP - $config->getEconomyStaffWageShareBp()), self::BP);

        // THE ONE FLOAT IN THE CHAIN AND THE ONE ROUNDING STEP. $sigma is a sum of pow() terms
        // and cannot be integer; everything before this line is exact pence and everything
        // after it is integer micro-pence.
        return (int) round(($playerBudget * self::WAGE_SCALE_UNIT) / ($weeks * $sigma));
    }

    /** SUM over the reference squad of ability^k. */
    private function referenceSquadAbilitySum(GameConfig $config, string $country, int $tier): float
    {
        $band = $this->abilityBand($country, $tier);
        $size = $this->referenceSquadSize($tier);
        $k    = $config->getEconomyWageCurveExponentBp() / self::BP;

        // INTEGER ABILITIES, NOT CONTINUOUS ONES, and that is a correctness fix rather than a
        // detail. `Player::$currentAbility` is an int, so a reference squad of continuous
        // abilities calibrates S_tier against players who cannot exist. The error is invisible
        // on a wide band and severe on a narrow one: England tier 8 spans 2-7, so 22 players
        // land on six distinct integers, and summing the continuous spread instead left the
        // real squad bill 2.5% over the budget it was supposedly calibrated to — caught by
        // `app:economy:wage-table`'s own self-consistency check rather than by inspection.
        if ($size <= 1) {
            return ((int) round(($band['min'] + $band['max']) / 2)) ** $k;
        }

        $sum  = 0.0;
        $span = $band['max'] - $band['min'];

        for ($i = 0; $i < $size; $i++) {
            $sum += ((int) round($band['min'] + $span * $i / ($size - 1))) ** $k;
        }

        return $sum;
    }

    /**
     * The tier's ability band, from starter-config.
     *
     * THE SINGLE SOURCE OF TRUTH FOR ABILITY, matching what the generators read.
     * WorldInitializationService::resolveAbilityRange() reads the same column with the same
     * const fallback, and the wage model must price the players the world actually makes. The
     * third table in the codebase, `gameConfig.leaguePlayerAbilityRanges`, is published to the
     * app and has no server-side generator consumer; it is deliberately not read here.
     *
     * @return array{min:int,max:int}
     */
    private function abilityBand(string $country, int $tier): array
    {
        $ranges     = $this->starterConfigRepository->getConfig()->getLeagueAbilityRanges();
        $configured = $ranges[$country][(string) $tier] ?? null;

        if (is_array($configured) && (int) ($configured['min'] ?? 0) > 0) {
            $min = (int) $configured['min'];
            $max = (int) $configured['max'];

            // Normalised on read, not trusted: the two halves are authored independently in the
            // admin, so a transposed pair is a legitimate state of the row — same reasoning as
            // ScoutWageCalculator::weeklyWageFor().
            return ['min' => min($min, $max), 'max' => max($min, $max)];
        }

        $fallback = \App\Service\WorldInitializationService::ABILITY_RANGES[$tier] ?? ['min' => 5, 'max' => 35];

        return ['min' => (int) $fallback['min'], 'max' => (int) $fallback['max']];
    }

    /**
     * The tier's raw npcSquadConfig row, for callers that need its staff counts too.
     *
     * EXPOSED RATHER THAN DUPLICATED. WageModelResolver calibrates staff pay against the same
     * complement the world generator actually staffs clubs with, and a second "how many staff a
     * tier has" table would be free to drift from this one — with the drift showing up as a
     * wage bill that does not match the share it was calibrated to.
     *
     * @return array<string, mixed>
     */
    public function npcSquadRow(int $tier): array
    {
        $row = $this->starterConfigRepository->getConfig()->getNpcSquadConfig()[(string) $tier] ?? null;

        return is_array($row) ? $row : [];
    }

    /**
     * How many players the reference squad has: the midpoint of the tier's configured band.
     *
     * Read from the same npcSquadConfig the world generator sizes real squads from, so the
     * figure the wage scale is calibrated against is the figure that actually gets paid.
     */
    private function referenceSquadSize(int $tier): int
    {
        $row = $this->npcSquadRow($tier);

        if ($row === []) {
            return 1;
        }

        $min = (int) ($row['playerMin'] ?? 0);
        $max = (int) ($row['playerMax'] ?? 0);

        return max(1, intdiv(min($min, $max) + max($min, $max), 2));
    }

    /** The flagged baseline country's own stored factor, or 0 when none is flagged. */
    private function baselineFactorBp(): int
    {
        return $this->baselineFactorBp ??= $this->countryProfileRepository
            ->findEconomyBaseline()?->getEconomyFactorBp() ?? 0;
    }
}
