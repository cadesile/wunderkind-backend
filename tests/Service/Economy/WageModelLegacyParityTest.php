<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\GameConfig;
use App\Entity\StarterConfig;
use App\Enum\EconomyWageModel;
use App\Enum\StaffRole;
use App\Repository\GameConfigRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\StarterConfigRepository;
use App\Service\Economy\EconomyScaleResolver;
use App\Service\Economy\WageModelResolver;
use PHPUnit\Framework\TestCase;

/**
 * `legacy` mode must produce byte-identical figures to the four sites it replaced.
 *
 * WHY THIS IS THE LOAD-BEARING TEST OF THE WHOLE STEP. Four copies of the same wage arithmetic
 * were collapsed into one resolver, and the figures they produced are baked into cached world
 * packs and into every generated row in the database. "We unified it and nothing moved" is a
 * claim, not a fact, until the numbers match — and a drift of one penny is a changed value
 * under an unchanged key on a cached surface, which this repo's compatibility rules count as a
 * break.
 *
 * ── THE OLD EXPRESSIONS ARE RESTATED HERE ON PURPOSE ──────────────────────────────────────
 *
 * `legacyPlayer()` and `legacyStaff()` below are the pre-unification arithmetic copied verbatim
 * out of WorldPackClubGenerationService, MarketPoolService and StaffGenerationService. Normally
 * duplicating an implementation in its own test is pointless — it just asserts the code equals
 * itself. Here it is the entire point: the test holds the OLD behaviour independently, so the
 * resolver is compared against history rather than against itself. These copies must never be
 * "refactored to call the resolver".
 *
 * ── WHY THE ROLL IS A PARAMETER ───────────────────────────────────────────────────────────
 *
 * `random_int()` is a CSPRNG and cannot be seeded, so byte-identity can never mean "the same
 * draw". It can only mean "the same formula applied to the same draw", which is exactly
 * testable — so the resolver takes the roll as an argument and the draw lives in a separate
 * one-line method.
 *
 * ── THE ODDITIES THAT HAD TO SURVIVE ──────────────────────────────────────────────────────
 *
 *  - The `(int)` cast TRUNCATES a float product. Rounding would move most wages by a penny.
 *  - The tier-walk's `<` is STRICT, so an ability exactly on a boundary falls into the band
 *    ABOVE: with the live bands (15/25/40/60) an ability-15 player gets x6, not x4.
 *  - An ability at or above the LAST `maxAbility` falls out of the loop entirely and lands on
 *    the last row via `end()`. The live table has no null catch-all row, so that fallback is
 *    load-bearing by accident rather than by design — and changing it would re-price every
 *    elite player in the world.
 */
class WageModelLegacyParityTest extends TestCase
{
    /** The live shape: four rows, no null catch-all. Copied from the 2026-11-12 export. */
    private const LIVE_TIERS = [
        ['maxAbility' => 15, 'playerMultiplier' => 4.0,  'staffMultiplier' => 2.0],
        ['maxAbility' => 25, 'playerMultiplier' => 6.0,  'staffMultiplier' => 4.0],
        ['maxAbility' => 40, 'playerMultiplier' => 15.0, 'staffMultiplier' => 8.0],
        ['maxAbility' => 60, 'playerMultiplier' => 30.0, 'staffMultiplier' => 12.0],
    ];

    // ── Players ───────────────────────────────────────────────────────────

    public function testPlayerWagesMatchTheOldExpressionAcrossEveryAbility(): void
    {
        $config   = $this->config();
        $resolver = $this->resolver($config);

        // Every ability a player can hold, against several rolls spanning the live 2000-2800
        // band plus the retired 10/40 defaults, so a config rollback is covered too.
        foreach (range(0, 100) as $ability) {
            foreach ([1, 10, 40, 2000, 2399, 2400, 2800] as $roll) {
                self::assertSame(
                    $this->legacyPlayer($ability, $roll),
                    $resolver->legacyPlayerPence($ability, $roll, $config),
                    "ability {$ability}, roll {$roll}",
                );
            }
        }
    }

    public function testTheTruncatingCastIsPreserved(): void
    {
        // A product that is not a whole number of pence. The old code truncated; rounding here
        // would move the figure up by one.
        $config   = $this->config([['maxAbility' => null, 'playerMultiplier' => 1.5, 'staffMultiplier' => 1.5]]);
        $resolver = $this->resolver($config);

        // 3 * 1 * 1.5 = 4.5 -> 4, not 5.
        self::assertSame(4, $resolver->legacyPlayerPence(3, 1, $config));
        self::assertSame(4, $this->legacyPlayer(3, 1, [['maxAbility' => null, 'playerMultiplier' => 1.5, 'staffMultiplier' => 1.5]]));
    }

    public function testTheStrictBoundaryIsPreserved(): void
    {
        // THE ODDITY MOST LIKELY TO BE "FIXED" BY ACCIDENT. `<` not `<=`, so ability exactly 15
        // skips the x4 row and takes x6.
        $config   = $this->config();
        $resolver = $this->resolver($config);

        self::assertSame(6.0, $resolver->multipliersFor(15, $config)['player'], 'ability 15 takes the band ABOVE');
        self::assertSame(4.0, $resolver->multipliersFor(14, $config)['player']);
        self::assertSame(15.0, $resolver->multipliersFor(25, $config)['player']);
    }

    public function testAnAbilityAboveTheLastRowFallsOntoThatRow(): void
    {
        // The live table has no null catch-all, so ability 60+ falls out of the loop and lands
        // on the last row through end(). Accidental, load-bearing, and asserted so it stays.
        $config   = $this->config();
        $resolver = $this->resolver($config);

        self::assertSame(30.0, $resolver->multipliersFor(60, $config)['player']);
        self::assertSame(30.0, $resolver->multipliersFor(99, $config)['player']);
        self::assertSame(12.0, $resolver->multipliersFor(99, $config)['staff']);
    }

    public function testANullCatchAllRowStillWins(): void
    {
        // The shape the entity DEFAULT has, as opposed to the live row. Both must work.
        $tiers = [
            ['maxAbility' => 20, 'playerMultiplier' => 2.0, 'staffMultiplier' => 1.0],
            ['maxAbility' => null, 'playerMultiplier' => 9.0, 'staffMultiplier' => 7.0],
        ];
        $config = $this->config($tiers);

        self::assertSame(9.0, $this->resolver($config)->multipliersFor(80, $config)['player']);
    }

    // ── Staff ─────────────────────────────────────────────────────────────

    public function testStaffWagesMatchTheOldExpression(): void
    {
        $config   = $this->config();
        $resolver = $this->resolver($config);

        foreach (range(0, 100) as $ability) {
            foreach ([2000, 8000, 10000, 30000, 60000] as $base) {
                self::assertSame(
                    $this->legacyStaff($base, $ability),
                    $resolver->legacyStaffPence($base, $ability, $config),
                    "ability {$ability}, base {$base}",
                );
            }
        }
    }

    public function testTheSeededRoleBandsAreTheOldHARDCODEDLiterals(): void
    {
        // The migration seeds config from StaffGenerationService's literals, so `legacy`
        // generation produces the same salaries it always did. If these drift, every staff
        // member the world generates changes price with no author.
        $config   = $this->config();
        $resolver = $this->resolver($config);

        self::assertSame([2000, 8000],   $resolver->staffRoleBand(StaffRole::COACH, $config));
        self::assertSame([10000, 30000], $resolver->staffRoleBand(StaffRole::MANAGER, $config));
        self::assertSame([12000, 35000], $resolver->staffRoleBand(StaffRole::DIRECTOR_OF_FOOTBALL, $config));
        self::assertSame([3000, 8500],   $resolver->staffRoleBand(StaffRole::FACILITY_MANAGER, $config));
        self::assertSame([20000, 60000], $resolver->staffRoleBand(StaffRole::CHAIRMAN, $config));
    }

    public function testATransposedRoleBandIsNormalisedRatherThanInverted(): void
    {
        // Monotonicity is what the funding projection leans on — a better coach must never be
        // cheaper. A hand-edited JSON column can legitimately arrive transposed.
        $config = $this->config();
        $config->setEconomyStaffRoleBandsPence(['coach' => [8000, 2000]]);

        self::assertSame([2000, 8000], $this->resolver($config)->staffRoleBand(StaffRole::COACH, $config));
    }

    public function testAnUnconfiguredRoleIsNotFree(): void
    {
        // A missing band falling through to 0 would be a free chairman, which reads as a
        // feature rather than as missing data.
        $config = $this->config();
        $config->setEconomyStaffRoleBandsPence(['coach' => [2000, 8000]]);

        self::assertSame([2000, 8000], $this->resolver($config)->staffRoleBand(StaffRole::CHAIRMAN, $config));
    }

    // ── The model gate ────────────────────────────────────────────────────

    public function testTheShippedModelIsLegacy(): void
    {
        self::assertSame(EconomyWageModel::LEGACY, (new GameConfig())->getEconomyWageModel());
    }

    public function testTurnoverModeStillFallsBackToLegacyForAnUnauthoredTier(): void
    {
        // The state on the day somebody flips the switch before filling the turnover table in:
        // pricing at zero would make every generated player free, so the legacy figure stands.
        $config = $this->config();
        $config->setEconomyWageModel(EconomyWageModel::TURNOVER);

        $resolver = $this->resolver($config);

        self::assertNull($resolver->turnoverPlayerPence(50, 'EN', 1, 50, $config));
        self::assertGreaterThan(0, $resolver->playerWeeklyPence(50, 'EN', 1, 50, $config));
    }

    public function testAPoolCallerWithNoCountryGetsTheLegacyFigureEvenUnderTurnover(): void
    {
        // A pool row is ONE row served to every country at every tier, so there is nothing to
        // price it against at generation time — it is stored legacy and scaled at serve time.
        $config = $this->config();
        $config->setEconomyWageModel(EconomyWageModel::TURNOVER);

        $resolver = $this->resolver($config);
        $priced   = $resolver->playerWeeklyPence(50, '', 0, 50, $config);

        // A legacy figure for ability 50 with the live roll band: 50 * [2000..2800] * x30.
        self::assertGreaterThanOrEqual($this->legacyPlayer(50, 2000), $priced);
        self::assertLessThanOrEqual($this->legacyPlayer(50, 2800), $priced);
    }

    // ── The reputation modifier ───────────────────────────────────────────

    public function testTheReputationModifierIsExactlyNeutralAtTheShippedSpread(): void
    {
        $config   = $this->config();
        $resolver = $this->resolver($config);

        foreach ([0, 25, 50, 75, 100] as $reputation) {
            self::assertSame(10000, $resolver->reputationModifierBp($reputation, $config), "reputation {$reputation}");
        }
    }

    public function testTheModifierIsNeutralAtReputationFiftyWhateverTheSpread(): void
    {
        // 50 is the calibration anchor, so the tier's bill only balances if the midpoint is
        // exactly 1.0 — otherwise a non-zero spread would inflate every tier's wage bill
        // rather than redistributing it.
        $config = $this->config();
        $config->setEconomyReputationSpreadBp(5000);

        self::assertSame(10000, $this->resolver($config)->reputationModifierBp(50, $config));
    }

    public function testTheModifierNeverMakesAPlayerFree(): void
    {
        $config = $this->config();
        $config->setEconomyReputationSpreadBp(1_000_000);

        self::assertGreaterThan(0, $this->resolver($config)->reputationModifierBp(0, $config));
    }

    // ───────── the OLD arithmetic, held independently — do not refactor ─────────

    /** @param list<array<string, mixed>>|null $tiers */
    private function legacyPlayer(int $ability, int $roll, ?array $tiers = null): int
    {
        $multiplier = $this->oldTierWalk($ability, $tiers ?? self::LIVE_TIERS)['player'];
        $baseWage   = $ability * $roll;

        return (int) ($baseWage * $multiplier);
    }

    private function legacyStaff(int $baseSalary, int $ability): int
    {
        return (int) ($baseSalary * $this->oldTierWalk($ability, self::LIVE_TIERS)['staff']);
    }

    /**
     * Copied verbatim from the three services' `getWageMultiplier()`, before unification.
     *
     * @param list<array<string, mixed>> $tiers
     * @return array{player: float, staff: float}
     */
    private function oldTierWalk(int $ability, array $tiers): array
    {
        foreach ($tiers as $tier) {
            if ($tier['maxAbility'] === null || $ability < $tier['maxAbility']) {
                return ['player' => (float) $tier['playerMultiplier'], 'staff' => (float) $tier['staffMultiplier']];
            }
        }

        $last = end($tiers);

        return ['player' => (float) ($last['playerMultiplier'] ?? 1.0), 'staff' => (float) ($last['staffMultiplier'] ?? 1.0)];
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /** @param list<array<string, mixed>>|null $tiers */
    private function config(?array $tiers = null): GameConfig
    {
        $config = new GameConfig();
        $config->setWageMultiplierTiers($tiers ?? self::LIVE_TIERS);
        // The live pair, not the 10/40 entity defaults.
        $config->setContractValueRandMin(2000);
        $config->setContractValueRandMax(2800);

        return $config;
    }

    private function resolver(GameConfig $config): WageModelResolver
    {
        $gameConfigRepo = $this->createStub(GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn($config);

        $starterRepo = $this->createStub(StarterConfigRepository::class);
        $starterRepo->method('getConfig')->willReturn(new StarterConfig());

        return new WageModelResolver(
            $gameConfigRepo,
            new EconomyScaleResolver(
                $gameConfigRepo,
                $starterRepo,
                $this->createStub(CountryProfileRepository::class),
            ),
        );
    }
}
