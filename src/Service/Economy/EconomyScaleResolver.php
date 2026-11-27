<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\GameConfig;
use App\Enum\PlayerPosition;
use App\Repository\GameConfigRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\StarterConfigRepository;
use App\Service\PlayerGenerationService;

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
        private readonly PoolConfigRepository     $poolConfigRepository,
        private readonly PlayerGenerationService  $playerGeneration,
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
     * What the owner may put into a club this season, in pence. 0 means the feature is off.
     *
     * `base x countryFactor x tierShare x inflationIndex(season)`, and this is **the only
     * server-side consumer of the inflation index**. Everything else the backend publishes is
     * base-year and the app compounds it — but a ceiling has to be checked against the season
     * being claimed for, or a save twenty seasons in would be held to year-one money.
     *
     * ── THE BASE IS THE TIER 1 MAXIMUM (owner ruling, 2026-11-27) ───────────────────────────
     *
     * The configured base is what an owner may put into a TIER 1 club of the baseline country.
     * Every lower tier gets the same share of it as its baseline turnover is of tier 1's:
     * `base x turnover[tier] / turnover[1]` — see seasonInvestmentTierShareBp(). So with
     * England at £331m / £114m / ... / £215k, a £10m base gives tier 2 about £3.4m and tier 8
     * about £6.5k. The country factor then applies on top, as it does to every money figure,
     * because "everything economic scales by country".
     *
     * One intdiv per factor, no floats.
     *
     * ZERO IS THE SHIPPED ANSWER, because `economySeasonInvestmentBasePence` ships at 0. Every
     * investment is then above the ceiling and the endpoint refuses — which is how the feature
     * and its two new error codes ship unreachable.
     */
    public function seasonInvestmentCeilingPence(string $country, int $tier, int $season): int
    {
        $base = $this->gameConfigRepository->getConfig()->getEconomySeasonInvestmentBasePence();

        if ($base <= 0) {
            return 0;
        }

        $countryScaled = intdiv($base * $this->countryFactorBp($country), self::BP);
        $tierScaled    = intdiv($countryScaled * $this->seasonInvestmentTierShareBp($country, $tier), self::BP);

        return intdiv($tierScaled * $this->inflationIndexBp($season), self::BP);
    }

    /**
     * The tier half of the season-investment ceiling, in basis points. 10000 = the full base.
     *
     * `turnover[tier] x 10000 / turnover[1]`, read from the BASELINE turnover table, so it is a
     * pure tier ratio — the country half is applied separately by the caller. Tier 1 is always
     * exactly 10000. The tier is clamped to the country's own depth first, the same way
     * resolve() clamps it, so a 4-tier country's bottom division reads row 4.
     *
     * FALLS BACK TO THE TIER FACTOR when the turnover table is unauthored (tier 1 or this tier
     * at 0). Zero turnover would otherwise make every lower tier's ceiling zero and switch the
     * feature off by accident; the tier-factor column was the ceiling's tier scale before this
     * ruling, so falling back to it is the "no change" direction.
     */
    public function seasonInvestmentTierShareBp(string $country, int $tier): int
    {
        $scale     = $this->resolve($country, $tier);
        $turnovers = $this->gameConfigRepository->getConfig()->getEconomyBaselineTurnoverPence();
        $top       = (int) ($turnovers[0] ?? 0);
        $own       = $scale->baselineTurnoverPence;

        if ($top <= 0 || $own <= 0) {
            return $scale->tierFactorBp;
        }

        // A lower tier should never out-earn tier 1, but a typo could make it do so; the base
        // is the MAXIMUM by ruling, so the share is capped at parity rather than trusted.
        return min(self::BP, intdiv($own * self::BP, $top));
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
     * The sum runs over N reference-squad members, where N is the midpoint of the tier's
     * playerMin/playerMax. Each member is an evenly spaced POTENTIAL across the tier's band
     * — evenly spaced rather than sampled, because a reference squad is a definition and a
     * random one would make S_tier differ between two calls for no reason — mapped to the
     * CURRENT ability the generator would actually give it. See referenceSquadAbilitySum():
     * the band is a potential range, and pricing it as current ability is what left real
     * squads paying a third of this budget.
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

    /**
     * SUM over the reference squad of (EXPECTED CURRENT ability)^k.
     *
     * ── WHY THE BAND IS NOT THE ANSWER ────────────────────────────────────────────────────
     *
     * `abilityBand()` is a POTENTIAL range: it is what gets handed to
     * `PlayerGenerationService::generate()` as `$potentialRange`, and current ability is
     * derived strictly BELOW it — `abilityTarget x potential` becomes the cap on six
     * attributes and current ability is their mean. A wage, meanwhile, is
     * `S_tier x currentAbility^k`. So until 2026-11-19 this sum was built from potentials
     * while every wage it priced was paid on current ability, and both halves of that
     * mismatch pushed the same way: a larger sigma makes S_tier smaller, and an ability below
     * potential makes the wage smaller again. Measured against real generated squads the
     * season bill came out at 21-35% of the player budget it is nominally calibrated against
     * — the single largest calibration error in the economy, and a textbook instance of two
     * places computing what should be one quantity.
     *
     * The fix is to ask the generator what it would actually make. Each member is still a rung
     * on an even spread of POTENTIALS across the authored band — a reference squad is a
     * definition, and a sampled one would make S_tier differ between two calls for no reason
     * — and is then mapped through `PlayerGenerationService::expectedCurrentAbility()` before
     * being raised to k. Nothing about the maturation curve, the youth ceiling, the position
     * fractions or the personality matrix is restated here; change any of them and this moves.
     *
     * ── THE AGE AND POSITION PROFILE, AND WHY IT IS AVERAGED RATHER THAN PAIRED ───────────
     *
     * Expected ability depends on age and position, and the reference squad had neither — it
     * has only a size. Both are taken from the generator rather than authored: ages are every
     * integer in `PoolConfig::firstTeamAgeRange()`, weighted equally because
     * `PlayerGenerationService::buildAnchors()` draws age with `random_int()` over exactly
     * that range, so a uniform weighting IS the generator's own distribution; positions are
     * weighted by PoolConfig's own position weights, the same four numbers
     * `WorldPackSnapshotBuilder::distributeByPosition()` divides a squad by.
     *
     * AVERAGED PER RUNG RATHER THAN PAIRED WITH IT, deliberately. Spreading ages across the
     * squad the way potentials are spread would make the oldest member always the most
     * talented, a correlation the generator does not have — age and potential are independent
     * draws — and `ability^k` is convex enough that the spurious correlation would inflate
     * sigma on its own. So each potential rung costs the MEAN of what it would cost across
     * the age and position profile, which is the independent joint the generator actually
     * produces.
     *
     * ── WHAT IS STILL APPROXIMATE ─────────────────────────────────────────────────────────
     *
     * This prices a squad of EXPECTED players, not the expected cost of a real squad: `x^k` is
     * convex, so a real draw costs somewhat more than its own mean does, most visibly where a
     * band is a handful of integers. That residual is left in rather than corrected, because
     * correcting it needs the variance of the draw and that is a different model from the one
     * a reference squad is defined to be. Measured per tier in
     * `docs/economy-loose-ends/a10-wage-scale-report.md`.
     *
     * INTEGER vs CONTINUOUS, retained from the previous implementation: the POTENTIAL rungs
     * are still rounded to integers before being mapped, because `Player::$potential` is an
     * int and a rung of 6.4 calibrates against a player who cannot exist. The expected
     * CURRENT ability that comes back is deliberately NOT rounded — it is the mean of a
     * distribution, not a player's attribute, and rounding a mean of 3.33 to 3 at England
     * tier 8 would move S_tier by 13%.
     */
    private function referenceSquadAbilitySum(GameConfig $config, string $country, int $tier): float
    {
        $k   = $config->getEconomyWageCurveExponentBp() / self::BP;
        $sum = 0.0;

        foreach ($this->referenceSquadAbilities($country, $tier, $k) as $ability) {
            $sum += $ability > 0.0 ? $ability ** $k : 0.0;
        }

        return $sum;
    }

    /**
     * The reference squad itself: one EXPECTED CURRENT ABILITY per member, in band order.
     *
     * PUBLIC BECAUSE THE GATE HAD ITS OWN COPY. `app:economy:wage-table`'s check 4 prices the
     * reference squad player by player and compares the total against the configured share of
     * turnover, which is the one check that catches a rounding error anywhere in the chain —
     * and it was rebuilding the squad from the ability band itself, a THIRD statement of what
     * a reference squad is after this class and `wunderkind-app`'s harness. That was survivable
     * while the definition was "an even spread of the band", because all three copies could
     * write the same two lines. It is not survivable now: the squad is the band mapped through
     * the generator's maturation curve, and a copy of that would be the same drift this change
     * exists to remove, one level up.
     *
     * Floats, deliberately not rounded: each member's figure is an AVERAGE over the age and
     * position profile, not an attribute a player carries. Rounding an average of 1.4 to 1 at
     * England tier 8 would move S_tier by a third.
     *
     * @param  float|null $k the wage curve exponent; read from config when omitted
     * @return list<float>
     */
    public function referenceSquadAbilities(string $country, int $tier, ?float $k = null): array
    {
        $k  ??= $this->gameConfigRepository->getConfig()->getEconomyWageCurveExponentBp() / self::BP;
        $band = $this->abilityBand($country, $tier);
        $size = $this->referenceSquadSize($tier);

        if ($size <= 1) {
            return [$this->expectedMemberAbility((int) round(($band['min'] + $band['max']) / 2), $k)];
        }

        $squad = [];
        $span  = $band['max'] - $band['min'];

        for ($i = 0; $i < $size; $i++) {
            $squad[] = $this->expectedMemberAbility((int) round($band['min'] + $span * $i / ($size - 1)), $k);
        }

        return $squad;
    }

    /**
     * The CURRENT ABILITY a reference-squad member of this POTENTIAL would have, averaged over
     * the generator's own first-team age range and position weights.
     *
     * Deterministic by construction: every input is a config row or a const, and the one thing
     * that could have made it otherwise — the generator's 5% prodigy branch — is weighted
     * inside `expectedAbilityTargetForAge()` rather than rolled. No `random_int()` is reachable
     * from here, which is what lets `S_tier` be a figure an operator can reproduce by hand.
     */
    private function expectedMemberAbility(int $potential, float $k): float
    {
        $pool     = $this->poolConfigRepository->getConfig();
        $ageRange = $pool->firstTeamAgeRange();

        $positionWeights = [
            PlayerPosition::GOALKEEPER->value => $pool->getPositionWeightGk(),
            PlayerPosition::DEFENDER->value   => $pool->getPositionWeightDef(),
            PlayerPosition::MIDFIELDER->value => $pool->getPositionWeightMid(),
            PlayerPosition::ATTACKER->value   => $pool->getPositionWeightAtt(),
        ];

        $weighted    = 0.0;
        $weightTotal = 0;

        for ($age = $ageRange['min']; $age <= $ageRange['max']; $age++) {
            foreach ($positionWeights as $position => $weight) {
                if ($weight <= 0) {
                    continue;
                }

                $ability      = $this->playerGeneration->expectedCurrentAbility($potential, $age, PlayerPosition::from($position));
                $weighted    += $weight * ($ability > 0.0 ? $ability ** $k : 0.0);
                $weightTotal += $weight;
            }
        }

        // No usable age range or every position weighted zero: fall back to the member's own
        // potential, which is the pre-2026-11-19 behaviour and keeps a misconfigured pool
        // resolving to a scale rather than to a division by zero.
        if ($weightTotal <= 0 || $k <= 0.0) {
            return (float) $potential;
        }

        // THE k-POWER MEAN, NOT THE ARITHMETIC ONE, and the difference is the whole point.
        // What the scale needs is the member's expected COST, and cost is `ability^k` with k at
        // 2.5 — a convex function, so the mean of the costs is strictly above the cost of the
        // mean. Averaging the abilities first and raising once would quietly price every member
        // below what his own age-and-position profile costs, and would do it worst where the
        // profile is widest: a 19 year old sits near 15% of his potential and a 30 year old
        // near 66%, so the spread this averages over is not a rounding detail.
        //
        // Inverting the power mean back into an ability, rather than returning the cost
        // directly, is what lets `referenceSquadAbilities()` hand the gate a squad it can price
        // through the real pricing function instead of through a second copy of this formula.
        return ($weighted / $weightTotal) ** (1 / $k);
    }

    /**
     * The tier's ability band, from starter-config.
     *
     * THE SINGLE SOURCE OF TRUTH FOR ABILITY, matching what the generators read.
     * WorldInitializationService::resolveAbilityRange() reads the same column with the same
     * const fallback, and the wage model must price the players the world actually makes. The
     * published `leaguePlayerAbilityRanges` key is now BUILT from this same column by
     * `EconomyConfigPublisher`, so there is no longer a second table to disagree with — the
     * `game_config` column of that name was dropped in `Version20261113160000`.
     *
     * PUBLIC SINCE 2026-11-12 because it had been copied three times — here,
     * EconomyWageTableCommand and EconomyAdminController — and three copies of "which abilities
     * exist at this tier" is three chances for the preview, the gate and the generator to
     * disagree about the thing they are all supposed to be checking.
     *
     * @return array{min:int,max:int}
     */
    public function abilityBand(string $country, int $tier): array
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

    /**
     * Every authored ability band, keyed country then tier — the whole table `abilityBand()`
     * answers one cell of.
     *
     * EXISTS SO THE PUBLISHED TABLE AND THE PRICING TABLE CANNOT BE DIFFERENT TABLES. They were:
     * `gameConfig.leaguePlayerAbilityRanges` was a separate stored column published to the app
     * while `starter_config.league_ability_ranges` priced and generated the players, and the
     * two disagreed at every England tier (55-95 against 40-90 at tier 1, 5-15 against 2-7 at
     * tier 8). That column is now dropped and the key is built from here. Squads were generated from one and judged against the other by nine app-side readers
     * covering recruiting, thinning, culling and contract renewal.
     *
     * Normalised through `abilityBand()` rather than returned raw, so a transposed pair is
     * fixed the same way here as it is on the pricing path — a second normalisation rule would
     * be a second way for the two to drift.
     *
     * ONLY AUTHORED COUNTRIES. A country with no starter-config row is OMITTED rather than
     * filled from `WorldInitializationService::ABILITY_RANGES`: that const is the pricing
     * path's last resort, and publishing it would put bands in the response that no generator
     * uses for that country — recreating the defect being fixed, in a new place. Absent keeps
     * meaning "not authored", and the consumer's own fallback decides what to do about it.
     *
     * @return array<string, array<int, array{min:int, max:int}>>
     */
    public function allAbilityBands(): array
    {
        $ranges = $this->starterConfigRepository->getConfig()->getLeagueAbilityRanges();
        $out    = [];

        foreach ($ranges as $country => $tiers) {
            $code = strtoupper(trim((string) $country));

            if ($code === '' || !is_array($tiers)) {
                continue;
            }

            foreach ($tiers as $tier => $band) {
                $t = (int) $tier;

                // The same guard abilityBand() applies: a row with no usable min is not an
                // authored band, it is an empty slot, and it must not publish as 0-0.
                if ($t < 1 || !is_array($band) || (int) ($band['min'] ?? 0) <= 0) {
                    continue;
                }

                $out[$code][$t] = $this->abilityBand($code, $t);
            }

            if (isset($out[$code])) {
                ksort($out[$code]);
            }
        }

        ksort($out);

        return $out;
    }

    /** The flagged baseline country's own stored factor, or 0 when none is flagged. */
    private function baselineFactorBp(): int
    {
        return $this->baselineFactorBp ??= $this->countryProfileRepository
            ->findEconomyBaseline()?->getEconomyFactorBp() ?? 0;
    }

    /**
     * The flagged baseline country's code, or null when none is flagged.
     *
     * Needed by anything that has to compare a country against the base year rather than merely
     * scale by it — the TV-table guard in `app:economy:wage-table` is the first such caller,
     * because `league.tv_deal` is a STORED ABSOLUTE that no factor reaches, so the only way to
     * tell whether it was scaled is to compare it with the baseline country's own figure.
     *
     * Zero baselines is a legal state (it resolves everything at parity), so null is an answer
     * rather than an error.
     */
    public function baselineCountryCode(): ?string
    {
        $code = $this->countryProfileRepository->findEconomyBaseline()?->getCode();

        return $code !== null && trim($code) !== '' ? strtoupper(trim($code)) : null;
    }
}
