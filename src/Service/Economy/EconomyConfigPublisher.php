<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\GameConfig;
use App\Repository\GameConfigRepository;

/**
 * Builds the economy blocks the client reads, in ONE place, for all three surfaces.
 *
 * ── WHY THIS IS A SERVICE AND NOT THREE INLINE ARRAYS ─────────────────────────────────────
 *
 * The economy has to reach the app through three places at once: the `gameConfig` block of the
 * sync response, `GET /api/game-config`, and — for the country-agnostic half — the public
 * `GET /api/starter-config`. The sync block is itself a TERNARY with two hand-written branches
 * (the entity read and a literal fallback for "no GameConfig row at all"), and the hard rule on
 * this work is that **both branches must carry an identical key set**.
 *
 * Three hand-maintained copies of a six-key block is a drift machine. Worse, the drift is
 * silent in the direction that matters: a key present on one branch and missing on the other
 * means a client reading it gets `undefined` only in the rare no-config case, which is exactly
 * the case nobody tests by hand. So the block is built once here and spread into all three.
 *
 * ── THE FALLBACK IS `new GameConfig()`, NOT RESTATED LITERALS ──────────────────────────────
 *
 * `clientBlock()` takes a nullable config and falls back to a fresh entity, so the defaults are
 * the column defaults by construction and cannot be restated wrongly. That is the lesson
 * `youthScholarshipWagePence` is commented with in SyncService: the client's guard is
 * `?? fallback` rather than a falsiness check, so a restated `0` sails straight past it and
 * silently becomes the live value. Reading the entity means there is one figure to change.
 *
 * ── WHAT THE APP GETS, AND WHAT IT DELIBERATELY DOES NOT ──────────────────────────────────
 *
 * `economyTierBaseline` carries **resolved absolutes for one country** — turnover and wage
 * scale per tier — and NOT the country factor that produced them. The app must never need to
 * know a country factor: it is a backend concept for composing figures, and publishing it
 * would invite the client to apply it a second time to something already scaled. Everything in
 * the table is base-year; the app applies inflation from `inflationRatePerSeasonBp`.
 *
 * The block is self-sufficient on purpose — it carries the curve exponent, the reputation
 * spread, the weeks and the scale unit alongside the per-tier rows. There is some overlap with
 * the public `economy` block on starter-config, and that is the right trade: a client should be
 * able to price a wage from one response rather than joining two endpoints, and these are a
 * handful of integers.
 *
 * ── TIER INDEX EQUALS ROW INDEX ───────────────────────────────────────────────────────────
 *
 * `tiers` has exactly `tierCount` rows and row i is tier i+1. A 4-tier country gets four rows
 * and is not stretched across eight; see EconomyScaleResolver on why that convention won.
 */
final class EconomyConfigPublisher
{
    public function __construct(
        private readonly GameConfigRepository $gameConfigRepository,
        private readonly EconomyScaleResolver $resolver,
        private readonly WageModelResolver    $wageModel,
        private readonly SponsorBandResolver  $sponsorBands,
    ) {}

    /**
     * The six additive keys for the sync `gameConfig` block and `GET /api/game-config`.
     *
     * IDENTICAL KEY SET WHATEVER THE ARGUMENTS. Pass null for either and the shape is the same;
     * only the values change. `SyncServiceGameConfigParityTest` asserts the two sync branches
     * agree, and they can only disagree if somebody stops calling this.
     *
     * @param  GameConfig|null $config  Null means "no row" — falls back to column defaults.
     * @param  string|null     $country Null means no country context, which resolves at parity.
     * @return array<string, mixed>
     */
    public function clientBlock(?GameConfig $config, ?string $country): array
    {
        $config ??= new GameConfig();

        return [
            'economyTierBaseline'       => $this->tierBaseline($config, $country),
            'inflationRatePerSeasonBp'  => $config->getInflationRatePerSeasonBp(),
            'matchdayFoodUptakePercent' => $config->getMatchdayFoodUptakePercent(),
            'npcSolvencyThresholds'     => $config->getNpcSolvencyThresholds(),
            'dofWageBillThresholdPct'   => $config->getDofWageBillThresholdPct(),
            'sponsorWeeklyPence'        => $config->getSponsorWeeklyPence(),
            // NULL UNTIL AN OPERATOR AUTHORS ONE, and null is the answer rather than a gap:
            // the app carries this ladder as a constant, so null means "use yours". A `[]`
            // cannot reach here — the setter collapses it to null — because an empty ladder
            // would read as a real one and switch the mechanic off while looking configured.
            'bankruptcyDeductionWeeks'  => $config->getBankruptcyDeductionWeeks(),
        ];
    }

    /**
     * The `economy` block on the PUBLIC `GET /api/starter-config`.
     *
     * BASELINE VALUES AND FACTOR TABLES ONLY, because this endpoint is unauthenticated and
     * country-agnostic — there is no club to resolve a country from, the same reason
     * `userStartingBalanceMin/MaxPence` publishes a range here rather than a resolved figure.
     * A client that needs its own country's resolved table reads it off sync or
     * `/api/game-config?country=`.
     *
     * `tierFactorsBp` is published even though the app never has to apply a factor: this is the
     * authoring view, and the landing page and operator tooling read it. The resolved table is
     * what a running game uses.
     *
     * @return array<string, mixed>
     */
    public function publicBlock(): array
    {
        $config = $this->gameConfigRepository->getConfig();

        return [
            'wageModel'              => $config->getEconomyWageModel()->value,
            'weeksPerSeason'         => $config->getEconomyWeeksPerSeason(),
            'wageToTurnoverRatioBp'  => $config->getEconomyWageToTurnoverRatioBp(),
            'wageCurveExponentBp'    => $config->getEconomyWageCurveExponentBp(),
            'staffWageShareBp'       => $config->getEconomyStaffWageShareBp(),
            'reputationSpreadBp'     => $config->getEconomyReputationSpreadBp(),
            'wageScaleUnit'          => EconomyScaleResolver::WAGE_SCALE_UNIT,
            'tierFactorsBp'          => $config->getEconomyTierFactorsBp(),
            'baselineTurnoverPence'  => $config->getEconomyBaselineTurnoverPence(),
            'inflationRatePerSeasonBp' => $config->getInflationRatePerSeasonBp(),
        ];
    }

    /**
     * `leaguePlayerAbilityRanges`, served from the table that actually prices players.
     *
     * ── WHAT CHANGED AND WHY ──────────────────────────────────────────────────────────────
     *
     * This key used to be read straight off `game_config.league_player_ability_ranges`, a
     * third ability table with **no server-side consumer at all** — no generator, no wage
     * code, no admin preview. Meanwhile `starter_config.league_ability_ranges` priced and
     * generated every player. The two disagreed at every England tier, and the app had nine
     * readers keying off the published one for recruiting, squad thinning, culling and
     * contract renewal. Squads were generated against one table and judged against the other.
     *
     * The key name, type and row shape are unchanged — only the source is. A consumer that was
     * already reading it keeps reading it, and now gets the figures the players were built
     * from.
     *
     * ── TWO THINGS A CONSUMER MUST BE READY FOR ───────────────────────────────────────────
     *
     * **More countries.** The old column held 8; the authored table holds 26. Eighteen
     * countries that previously appeared nowhere in this key now appear, so a client that fell
     * through to its own defaults for them will stop doing so.
     *
     * **Short countries.** Scotland has **four** tiers, not eight. Every entry this key has
     * ever carried had eight, so anything indexing tiers 1-8 directly will find nothing at 5-8.
     * The tier numbers are published explicitly on each row for exactly this reason — read
     * them, do not assume a count.
     *
     * `game_config.league_player_ability_ranges` is left in place and simply stops being read.
     * Dropping it would be a schema change for no gain, and keeping it costs a column.
     *
     * @return list<array{country: string, leagues: list<array{tier: int, min: int, max: int}>}>
     */
    public function leaguePlayerAbilityRanges(): array
    {
        $out = [];

        foreach ($this->resolver->allAbilityBands() as $country => $tiers) {
            $leagues = [];

            foreach ($tiers as $tier => $band) {
                $leagues[] = ['tier' => $tier, 'min' => $band['min'], 'max' => $band['max']];
            }

            if ($leagues !== []) {
                $out[] = ['country' => $country, 'leagues' => $leagues];
            }
        }

        return $out;
    }

    /**
     * The resolved per-tier table for one country.
     *
     * @return array<string, mixed>
     */
    private function tierBaseline(GameConfig $config, ?string $country): array
    {
        // An empty country resolves at parity: findByCode('') finds nothing, so no factor
        // applies and tierCountFor() falls back to eight. That is what makes `?country=`
        // optional on a public endpoint without a second code path.
        $code      = ($country !== null && trim($country) !== '') ? strtoupper(trim($country)) : null;
        $tierCount = $this->resolver->tierCountFor((string) $code);

        $tiers = [];
        for ($tier = 1; $tier <= $tierCount; $tier++) {
            $scale = $this->resolver->resolve((string) $code, $tier);

            $tiers[] = [
                'tier'                => $tier,
                'turnoverPence'       => $scale->turnoverPence,
                'wageScaleMicroPence' => $scale->wageScaleMicroPence,
                // So a client can tell "the operator has not authored this tier yet" from
                // "this tier is genuinely worth nothing", which a bare 0 cannot express.
                'resolvable'          => $scale->isResolvable,
                // THE PER-TIER SCHOLARSHIP, and the reason it is here rather than only at the
                // top level: the flat `youthScholarshipWagePence` is right at the upper tiers
                // and absurd at the lower ones, where the turnover model makes an average
                // first-team wage smaller than it. A client should prefer this figure and keep
                // the flat key as its fallback — which is exactly what an older build already
                // does by not knowing about this one.
                'youthScholarshipPence' => $this->wageModel->youthScholarshipPence((string) $code, $tier, $config),
            ];
        }

        return [
            'country'             => $code,
            // ── WHY A READ HAS TO SAY WHERE IT CAME FROM ──────────────────────────────────
            //
            // `?country=` is optional, and a request without it does NOT return England — it
            // resolves at the const fallback, because findByCode('') finds no profile and
            // every factor lands at parity. The two reads look alike and are not: tier-1
            // `wageScaleMicroPence` comes back as 217,517,151 with no country against
            // England's 390,404,650.
            //
            // That is not hypothetical. The app's release gate was built on the no-country
            // read, concluded every tier was solvent, and the conclusion had to be withdrawn.
            // `country` was already published and was already null in that case, but null is
            // easy to read as "country-agnostic, therefore fine" rather than "these are not
            // anybody's figures". This says it in a word the consumer can refuse on, and the
            // app's gate and sync intake do refuse `fallback`.
            //
            // Additive and always present, so it cannot be mistaken for a key that appears
            // only when something is wrong.
            'source'              => $code === null ? 'fallback' : 'country',
            'tierCount'           => $tierCount,
            // COUNTRY-LEVEL, NOT PER-TIER, and deliberately narrower than the wage chain: a
            // league's tier variation is already expressed by how many sponsors it has and
            // which size bands they draw from, so applying the tier factor here too would
            // re-tier an already-tiered mechanic. Resolved so a client rolling its own sponsor
            // offers agrees with the `sponsorPot` the server rolled — the six flat
            // `smallSponsorMin`-style keys are untouched and remain the fallback.
            'sponsorBandsPence'   => $this->sponsorBands->bandsFor((string) $code, $config),
            'wageModel'           => $config->getEconomyWageModel()->value,
            'weeksPerSeason'      => $config->getEconomyWeeksPerSeason(),
            'wageCurveExponentBp' => $config->getEconomyWageCurveExponentBp(),
            'reputationSpreadBp'  => $config->getEconomyReputationSpreadBp(),
            'wageScaleUnit'       => EconomyScaleResolver::WAGE_SCALE_UNIT,
            'tiers'               => $tiers,
        ];
    }
}
