<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\GameConfig;
use App\Enum\EconomyWageModel;
use App\Enum\StaffRole;
use App\Repository\GameConfigRepository;

/**
 * What a generated player or staff member costs per week. The only place that answers it.
 *
 * ── WHAT THIS REPLACED ────────────────────────────────────────────────────────────────────
 *
 * Four copies of the same arithmetic, in four files, which is three too many for a figure that
 * is baked into generated rows and then read for the life of a save:
 *
 *   WorldPackClubGenerationService::playerWageMultiplier()  — the tier-walk, player half only
 *   MarketPoolService::getWageMultiplier()                  — the tier-walk, both halves
 *   StaffGenerationService::getWageMultiplier()             — the tier-walk, both halves
 *   GenerateMarketDataCommand                               — neither; its own `random_int(10, 40)`
 *
 * The fourth is the reason this matters rather than being tidiness: that command ignored
 * `contractValueRandMin/Max` entirely and ignored the tier multipliers entirely, so a player it
 * created was priced on a 70-year-old pair of numbers while an identical player from the pool
 * was priced on the live config. It also left every scout it made on a wage of ZERO, which
 * `BackfillScoutWagesCommand`'s zero-sentinel then treats as "never priced".
 *
 * ── `legacy` MUST BE BYTE-IDENTICAL, AND THAT CONSTRAINS THE SHAPE OF THIS CLASS ───────────
 *
 * The old sites computed `(int) ($ability * $roll * $multiplier)` — an INT cast, which
 * truncates rather than rounds, on a float product. Reproducing that exactly is the whole
 * contract of `legacy` mode, so the arithmetic is kept in `legacyPlayerPence()` verbatim down to
 * the cast, and the random roll is a PARAMETER rather than drawn inside it.
 *
 * Why a parameter: `random_int()` is a CSPRNG and cannot be seeded, so "byte-identical" can
 * never mean "the same draw". It can only mean "the same formula applied to the same draw" —
 * which is testable exactly, and is what `WageModelLegacyParityTest` asserts across the whole
 * ability range against the old inlined expressions. The draw itself lives in `legacyRoll()`.
 *
 * The tier-walk keeps its oddities too, deliberately: `$ability < $tier['maxAbility']` is a
 * STRICT comparison, so a player exactly on a boundary falls into the band ABOVE — with the
 * live bands of 15/25/40/60 an ability-15 player gets the x6 multiplier, not x4. That is what
 * every row in the world was priced with, so it is behaviour, not a bug to quietly fix here.
 *
 * ── TWO POPULATIONS, TWO TREATMENTS ───────────────────────────────────────────────────────
 *
 * This is the distinction that governs where the model can be applied at all:
 *
 *  - **Per-country rows** — world-pack club players and staff — are generated for a known
 *    country and tier, so they can be priced at generation and the figure stored. That is why
 *    flipping the model needs a `WORLD_PACK_VERSION` bump: packs hold wages.
 *  - **Shared-pool rows** — the market pool's players, coaches and scouts — are ONE row served
 *    to every country at every tier. There is no country or tier to price them by at generation
 *    time, so they are stored at the legacy figure always and priced AT SERVE TIME from the
 *    requesting club's country and tier. Flipping the model therefore changes nothing stored
 *    about the pool, and flipping back is instant.
 */
final class WageModelResolver
{
    /** What the tier-walk falls back to when the config has no usable band at all. */
    private const FALLBACK_MULTIPLIER = 1.0;

    /** The ability the staff scale is anchored at, so a mid-ability complement costs the budget. */
    private const STAFF_REFERENCE_ABILITY = 50;

    private const BP = 10_000;

    public function __construct(
        private readonly GameConfigRepository $gameConfigRepository,
        private readonly EconomyScaleResolver $scaleResolver,
    ) {}

    public function model(?GameConfig $config = null): EconomyWageModel
    {
        return ($config ?? $this->gameConfigRepository->getConfig())->getEconomyWageModel();
    }

    // ── Players ───────────────────────────────────────────────────────────

    /**
     * A generated player's weekly wage in pence.
     *
     * `$country` and `$tier` are ignored by `legacy` and required by `turnover`. A caller with
     * no country or tier to offer — the shared market pool — passes empty values and gets the
     * legacy figure whatever the model says, because there is nothing to price against; see the
     * class docblock on the two populations.
     */
    public function playerWeeklyPence(
        int $ability,
        string $country = '',
        int $tier = 0,
        int $clubReputation = 50,
        ?GameConfig $config = null,
    ): int {
        $config = $config ?? $this->gameConfigRepository->getConfig();

        if ($this->model($config) === EconomyWageModel::TURNOVER && $country !== '' && $tier > 0) {
            $turnover = $this->turnoverPlayerPence($ability, $country, $tier, $clubReputation, $config);

            // A tier whose turnover nobody has authored falls back rather than pricing at zero.
            // `isResolvable` is false for every tier until an operator fills the table in, so
            // this is the path the model takes on the day it is switched on with no numbers.
            if ($turnover !== null) {
                return $turnover;
            }
        }

        return $this->legacyPlayerPence($ability, $this->legacyRoll($config), $config);
    }

    /**
     * LEGACY, verbatim: `(int) ($ability * $roll * $playerMultiplier)`.
     *
     * The int cast TRUNCATES and that is preserved on purpose — it is what every player in the
     * world was priced with. Rounding here would move every generated wage by up to a penny,
     * which is a changed value under an unchanged key on a cached surface.
     */
    public function legacyPlayerPence(int $ability, int $roll, ?GameConfig $config = null): int
    {
        return (int) ($ability * $roll * $this->multipliersFor($ability, $config)['player']);
    }

    /** The random multiplier the legacy model draws, from the live config bounds. */
    public function legacyRoll(?GameConfig $config = null): int
    {
        $config = $config ?? $this->gameConfigRepository->getConfig();

        $min = $config->getContractValueRandMin();
        $max = $config->getContractValueRandMax();

        // Normalised rather than trusted: the two are set independently in the admin, so a
        // transposed pair is a real state of the row — same reasoning as
        // ScoutWageCalculator::weeklyWageFor().
        return random_int(min($min, $max), max($min, $max));
    }

    /**
     * TURNOVER: `S_tier x ability^k x reputationModifier`, or null when the tier is unauthored.
     *
     * Deterministic — no roll. The random variation the legacy model leans on is not part of
     * this model's definition; what replaces it is the ability curve, which is a far stronger
     * signal than a 2000-2800 multiplier ever was.
     */
    public function turnoverPlayerPence(
        int $ability,
        string $country,
        int $tier,
        int $clubReputation = 50,
        ?GameConfig $config = null,
    ): ?int {
        $config = $config ?? $this->gameConfigRepository->getConfig();
        $scale  = $this->scaleResolver->resolve($country, $tier);

        if (!$scale->isResolvable || $scale->wageScaleMicroPence <= 0) {
            return null;
        }

        $k     = $config->getEconomyWageCurveExponentBp() / self::BP;
        $shape = max(0, $ability) ** $k;

        // One multiply, then the single division out of micro-pence. The reputation modifier is
        // applied as basis points so it stays integer until that last step.
        $micro = (int) round($scale->wageScaleMicroPence * $shape);
        $micro = intdiv($micro * $this->reputationModifierBp($clubReputation, $config), self::BP);

        return intdiv($micro, EconomyScaleResolver::WAGE_SCALE_UNIT);
    }

    /**
     * `1 + (reputation - 50)/50 x spread`, as basis points. Exactly 10000 at the shipped spread
     * of 0, and exactly 10000 at reputation 50 whatever the spread.
     *
     * THE CALIBRATION IS DERIVED AT 1.0, so a non-zero spread REDISTRIBUTES a tier's wage bill
     * between its clubs rather than inflating it. Worth stating because the obvious reading of
     * "reputation raises wages" is that the bill goes up.
     */
    public function reputationModifierBp(int $clubReputation, ?GameConfig $config = null): int
    {
        $spread = ($config ?? $this->gameConfigRepository->getConfig())->getEconomyReputationSpreadBp();

        if ($spread === 0) {
            return self::BP;
        }

        $delta = intdiv((max(0, min(100, $clubReputation)) - 50) * $spread, 50);

        // Floored at 1bp rather than 0: a reputation modifier must never make a player free.
        return max(1, self::BP + $delta);
    }

    // ── Staff ─────────────────────────────────────────────────────────────

    /**
     * A generated staff member's weekly salary in pence.
     *
     * UNDER `legacy` this is `(int) (random_int(band) x staffMultiplier)` — StaffGenerationService's
     * arithmetic, with the band now read from config instead of a literal.
     *
     * UNDER `turnover` the band does both jobs described on
     * `GameConfig::$economyStaffRoleBandsPence`: ability interpolates WITHIN the role's band,
     * and the result is scaled by a single per-tier factor chosen so the tier's reference staff
     * complement — the counts in `npcSquadConfig` — costs the configured staff share of
     * turnover. Reusing the band for the ability shape is what avoids asking an operator to
     * re-tune five roles against a knob that did not exist before.
     */
    public function staffWeeklyPence(
        StaffRole $role,
        int $ability,
        string $country = '',
        int $tier = 0,
        ?GameConfig $config = null,
    ): int {
        $config = $config ?? $this->gameConfigRepository->getConfig();

        if ($this->model($config) === EconomyWageModel::TURNOVER && $country !== '' && $tier > 0) {
            $turnover = $this->turnoverStaffPence($role, $ability, $country, $tier, $config);

            if ($turnover !== null) {
                return $turnover;
            }
        }

        [$min, $max] = $this->staffRoleBand($role, $config);

        return $this->legacyStaffPence(random_int($min, $max), $ability, $config);
    }

    /** LEGACY, verbatim: `(int) ($baseSalary * $staffMultiplier)`. Truncating cast preserved. */
    public function legacyStaffPence(int $baseSalary, int $ability, ?GameConfig $config = null): int
    {
        return (int) ($baseSalary * $this->multipliersFor($ability, $config)['staff']);
    }

    /** TURNOVER staff pay, or null when the tier's turnover is unauthored. */
    public function turnoverStaffPence(
        StaffRole $role,
        int $ability,
        string $country,
        int $tier,
        ?GameConfig $config = null,
    ): ?int {
        $config = $config ?? $this->gameConfigRepository->getConfig();
        $scale  = $this->scaleResolver->resolve($country, $tier);

        if (!$scale->isResolvable) {
            return null;
        }

        $budgetPerWeek = $this->staffBudgetPerWeekPence($scale->turnoverPence, $config);
        $reference     = $this->referenceComplementPence($tier, $config);

        if ($budgetPerWeek <= 0 || $reference <= 0) {
            return null;
        }

        // One scale for the whole tier, so the roles keep the relative pay their bands encode.
        $scaleBp  = intdiv($budgetPerWeek * self::BP, $reference);
        $inBand   = $this->staffBandPositionPence($role, $ability, $config);

        return intdiv($inBand * $scaleBp, self::BP);
    }

    /** @return array{int, int} The role's configured weekly pence band. */
    public function staffRoleBand(StaffRole $role, ?GameConfig $config = null): array
    {
        $bands = ($config ?? $this->gameConfigRepository->getConfig())->getEconomyStaffRoleBandsPence();
        $band  = $bands[$role->value] ?? null;

        if (!is_array($band) || !isset($band[0], $band[1])) {
            // Falls back to the coach band rather than to zero: an unconfigured role costing
            // nothing is a free chairman, which reads as a feature rather than as missing data.
            $band = $bands[StaffRole::COACH->value] ?? [2000, 8000];
        }

        $lo = max(0, (int) $band[0]);
        $hi = max(0, (int) $band[1]);

        return [min($lo, $hi), max($lo, $hi)];
    }

    /**
     * What a youth scholar costs per week at this tier, in pence.
     *
     * `min(flat figure, capBp x the tier's WORST first-team wage)`, or the flat figure
     * untouched when the turnover model cannot price the tier.
     *
     * THE DEFECT: the flat `youthScholarshipWagePence` is £250/week and the turnover model
     * makes an average England tier-8 first-team wage about £127, so a thirteen-year-old was
     * set to out-earn an average professional by nearly 2x. See
     * `GameConfig::$economyYouthScholarshipCapBp` for why this is a cap rather than a
     * replacement, and why it is anchored to the bottom of the band rather than the average.
     *
     * PROVABLY INERT UNTIL THE MODEL IS FLIPPED. The cap applies only under `turnover` — not
     * merely when a turnover table happens to be authored — so the flat figure stands through
     * the whole half-configured period an operator passes through while filling the table in.
     * That is the shipped state, so this method cannot move a published figure on its own.
     *
     * NEVER ZERO FROM THE CAP. A tier whose worst wage rounds to nothing would otherwise make
     * scholars free, and free is a different mechanic from cheap — `SyncService`'s own comment
     * on this figure makes the point that the client guards with `?? fallback` rather than
     * falsiness, so a 0 sails straight through. Floored at 1p.
     */
    public function youthScholarshipPence(string $country, int $tier, ?GameConfig $config = null): int
    {
        $config = $config ?? $this->gameConfigRepository->getConfig();
        $flat   = $config->getYouthScholarshipWagePence();
        $capBp  = $config->getEconomyYouthScholarshipCapBp();

        // GATED ON THE MODEL, NOT JUST ON RESOLVABILITY, and the distinction is load-bearing.
        // A turnover table can be authored while the model is still `legacy` — it is the
        // half-configured state every operator passes through — and legacy tier-8 wages average
        // around £480, where the flat £250 is already sensible. Capping against a band wage the
        // world is not being priced by would make scholars absurdly cheap relative to what
        // their teammates actually earn. Caught by a test, not by inspection.
        if ($capBp <= 0 || $country === '' || $tier < 1 || $this->model($config) !== EconomyWageModel::TURNOVER) {
            return $flat;
        }

        $band   = $this->scaleResolver->abilityBand($country, $tier);
        $worst  = $this->turnoverPlayerPence($band['min'], $country, $tier, 50, $config);

        // Null under `legacy` or for an unauthored tier — the flat figure is the answer there.
        if ($worst === null) {
            return $flat;
        }

        return min($flat, max(1, intdiv($worst * $capBp, self::BP)));
    }

    /**
     * A shared-pool wage scaled for the club reading it. The serve-time path.
     *
     * COUNTRY AND TIER FACTOR ONLY — NOT a re-price from the ability curve, and the difference
     * matters. A pool coach's or scout's pay is driven by `coachingAbility` or `experience`
     * against bands the turnover model has no opinion about (a scout's wage comes from
     * PoolConfig's own floor and ceiling, interpolated by experience), so there is no `S_tier`
     * to re-derive it from. What the turnover model DOES have an opinion about is that money
     * means different things in different countries and divisions, and that is exactly what the
     * combined factor expresses.
     *
     * Players are the exception and are re-priced properly, because they DO have an ability
     * curve — see PlayerBrowseSerializer.
     *
     * Nothing stored changes. That is what keeps `ScoutWageCalculator` reproducible from
     * `(experience, PoolConfig)` alone, which `BackfillScoutWagesCommand --force` depends on to
     * re-derive every scout in the world.
     */
    public function scalePoolWagePence(int $storedPence, string $country, int $tier): int
    {
        return $this->scaleResolver->scalePence($storedPence, $country, $tier);
    }

    // ── The tier-walk, previously copy-pasted three times ─────────────────

    /**
     * The `wageMultiplierTiers` walk.
     *
     * STRICT `<`, PRESERVED. A player exactly on a boundary falls into the band above — with
     * the live bands (15/25/40/60) an ability-15 player gets x6, not x4. Every row in the world
     * was priced that way.
     *
     * The trailing `end($tiers)` fallback is also kept: the live table has no null catch-all
     * row, so an ability at or above the last `maxAbility` falls out of the loop and lands on
     * the last row by accident rather than by design. Changing that would re-price every elite
     * player.
     *
     * @return array{player: float, staff: float}
     */
    public function multipliersFor(int $ability, ?GameConfig $config = null): array
    {
        $tiers = ($config ?? $this->gameConfigRepository->getConfig())->getWageMultiplierTiers();

        foreach ($tiers as $tier) {
            if (($tier['maxAbility'] ?? null) === null || $ability < $tier['maxAbility']) {
                return [
                    'player' => (float) ($tier['playerMultiplier'] ?? self::FALLBACK_MULTIPLIER),
                    'staff'  => (float) ($tier['staffMultiplier'] ?? self::FALLBACK_MULTIPLIER),
                ];
            }
        }

        $last = end($tiers);

        return [
            'player' => (float) ($last['playerMultiplier'] ?? self::FALLBACK_MULTIPLIER),
            'staff'  => (float) ($last['staffMultiplier'] ?? self::FALLBACK_MULTIPLIER),
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function staffBudgetPerWeekPence(int $turnoverPence, GameConfig $config): int
    {
        $wageBudget = intdiv($turnoverPence * $config->getEconomyWageToTurnoverRatioBp(), self::BP);
        $staffShare = intdiv($wageBudget * $config->getEconomyStaffWageShareBp(), self::BP);
        $weeks      = max(1, $config->getEconomyWeeksPerSeason());

        return intdiv($staffShare, $weeks);
    }

    /**
     * What the tier's reference staff complement costs per week at mid ability, unscaled.
     *
     * The counts come from `npcSquadConfig` — the same table the world generator staffs real
     * clubs from — so the complement the scale is calibrated against is the complement that
     * actually gets paid.
     */
    private function referenceComplementPence(int $tier, GameConfig $config): int
    {
        $total = 0;

        foreach ($this->referenceComplement($tier) as $roleValue => $count) {
            $role = StaffRole::tryFrom($roleValue);

            if ($role === null || $count <= 0) {
                continue;
            }

            $total += $count * $this->staffBandPositionPence($role, self::STAFF_REFERENCE_ABILITY, $config);
        }

        return $total;
    }

    /**
     * Role counts for a tier, read off starter-config's npcSquadConfig.
     *
     * Deliberately NOT injected as a separate table. The counts already exist and are already
     * what generation uses; a second list of "how many staff a tier has" would be free to drift
     * from the first, and the drift would show up as a wage bill that does not match the share
     * it was calibrated to.
     *
     * @return array<string, int>
     */
    private function referenceComplement(int $tier): array
    {
        $row = $this->scaleResolver->npcSquadRow($tier);

        return [
            StaffRole::MANAGER->value              => (int) ($row['managerCount'] ?? 0),
            StaffRole::COACH->value                => (int) ($row['coachCount'] ?? 0),
            StaffRole::DIRECTOR_OF_FOOTBALL->value => (int) ($row['directorOfFootballCount'] ?? 0),
            StaffRole::FACILITY_MANAGER->value     => (int) ($row['facilityManagerCount'] ?? 0),
            StaffRole::CHAIRMAN->value             => (int) ($row['chairmanCount'] ?? 0),
        ];
    }

    /**
     * Where an ability sits inside a role's band, in pence. Monotonic in ability by construction.
     *
     * Monotonicity is the property the funding projection leans on — a better coach must never
     * be cheaper, or "hire the better one" reads as a bug rather than a bargain. Same
     * requirement `ScoutWageCalculator` documents.
     */
    private function staffBandPositionPence(StaffRole $role, int $ability, GameConfig $config): int
    {
        [$min, $max] = $this->staffRoleBand($role, $config);
        $clamped     = max(0, min(100, $ability));

        return $min + intdiv(($max - $min) * $clamped, 100);
    }
}
