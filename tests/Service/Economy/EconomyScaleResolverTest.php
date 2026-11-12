<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\GameConfig;
use App\Entity\Location\CountryProfile;
use App\Entity\StarterConfig;
use App\Repository\GameConfigRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\StarterConfigRepository;
use App\Service\Economy\EconomyScaleResolver;
use PHPUnit\Framework\TestCase;

/**
 * The one place country and tier compose into money.
 *
 * THE FIRST TEST IS THE ONE THAT MATTERS AND IT ASSERTS THAT NOTHING HAPPENS. The whole of this
 * work ships inert: factors default to parity, the inflation rate to zero, the turnover table
 * to unauthored. If "apply the migrations and change nothing" is not provable then the deploy
 * is a gamble, so it is pinned first and in several directions.
 *
 * Everything else here guards an arithmetic property that is invisible when it breaks. A wrong
 * normalisation, a float sneaking into the chain, or a tier resolving to a null row all surface
 * as money that is slightly or entirely wrong in generated rows that outlive the request —
 * never as an exception.
 *
 * Unit-level with stubbed repositories on purpose: these are properties of the arithmetic, and
 * routing them through a database would make the assertions depend on whatever operator content
 * the test database happens to hold.
 */
class EconomyScaleResolverTest extends TestCase
{
    // ── Inert by default ──────────────────────────────────────────────────

    public function testTheShippedDefaultsChangeNothing(): void
    {
        $resolver = $this->resolver(new GameConfig(), $this->starterConfig(), [
            'EN' => $this->profile('EN', isBaseline: true),
        ]);

        foreach ([1, 4, 8] as $tier) {
            $scale = $resolver->resolve('EN', $tier);

            self::assertSame(10000, $scale->countryFactorBp, "tier {$tier} country factor");
            self::assertSame(10000, $scale->tierFactorBp, "tier {$tier} tier factor");
            self::assertSame(10000, $scale->combinedFactorBp, "tier {$tier} combined");
        }
    }

    public function testScalingPenceAtTheDefaultsIsTheIdentity(): void
    {
        $resolver = $this->resolver(new GameConfig(), $this->starterConfig(), [
            'EN' => $this->profile('EN', isBaseline: true),
        ]);

        foreach ([0, 1, 999, 1_234_567, 9_999_999_999] as $pence) {
            self::assertSame($pence, $resolver->scalePence($pence, 'EN', 5));
        }
    }

    public function testAnUnauthoredTurnoverTableIsNotResolvable(): void
    {
        // The shipped state. Zero turnover makes the wage scale undefined, not cheap, so the
        // resolver says so rather than handing back a scale of nothing that reads as "free".
        $resolver = $this->resolver(new GameConfig(), $this->starterConfig(), [
            'EN' => $this->profile('EN', isBaseline: true),
        ]);

        $scale = $resolver->resolve('EN', 1);

        self::assertFalse($scale->isResolvable);
        self::assertSame(0, $scale->wageScaleMicroPence);
        self::assertSame(0, $scale->turnoverPence);
    }

    public function testNoBaselineCountryAtAllStillResolvesAtParity(): void
    {
        // A fresh database, or before the migration's postUp has run. A half-configured economy
        // must degrade to "no scaling" rather than to an exception.
        $resolver = $this->resolver(new GameConfig(), $this->starterConfig(), [
            'ES' => $this->profile('ES', factorBp: 4000),
        ]);

        self::assertSame(10000, $resolver->resolve('ES', 1)->countryFactorBp);
        self::assertSame(500, $resolver->scalePence(500, 'ES', 1));
    }

    public function testAnInflationRateOfZeroIsAlwaysTheIdentity(): void
    {
        $resolver = $this->resolver(new GameConfig(), $this->starterConfig(), []);

        foreach ([1, 2, 10, 50, 200, 100_000] as $season) {
            self::assertSame(10000, $resolver->inflationIndexBp($season), "season {$season}");
        }
    }

    // ── The country factor is a ratio, not an absolute ────────────────────

    public function testTheBaselineCountryIsParityWithItselfWhateverItStores(): void
    {
        // An operator can reasonably set England to 12000 thinking in absolutes. The baseline
        // must still be parity with itself, or every country's factor shifts as a side effect
        // of editing the baseline's own number.
        $resolver = $this->resolver(new GameConfig(), $this->starterConfig(), [
            'EN' => $this->profile('EN', factorBp: 12000, isBaseline: true),
        ]);

        self::assertSame(10000, $resolver->resolve('EN', 1)->countryFactorBp);
    }

    public function testAnotherCountryIsNormalisedAgainstTheBaselinesOwnValue(): void
    {
        $resolver = $this->resolver(new GameConfig(), $this->starterConfig(), [
            'EN' => $this->profile('EN', factorBp: 20000, isBaseline: true),
            'ES' => $this->profile('ES', factorBp: 10000),
        ]);

        // Spain stores half of England's figure, so it resolves to 50% — not to the 10000 its
        // own column holds.
        self::assertSame(5000, $resolver->resolve('ES', 1)->countryFactorBp);
        self::assertSame(500, $resolver->scalePence(1000, 'ES', 1));
    }

    public function testACountryWithNoProfileRowFallsBackToParity(): void
    {
        $resolver = $this->resolver(new GameConfig(), $this->starterConfig(), [
            'EN' => $this->profile('EN', isBaseline: true),
        ]);

        self::assertSame(10000, $resolver->resolve('ZZ', 1)->countryFactorBp);
        self::assertSame(8, $resolver->tierCountFor('ZZ'));
    }

    // ── Raw row indexing ──────────────────────────────────────────────────

    public function testTierFactorsAreIndexedRawNotStretched(): void
    {
        $config = (new GameConfig())
            ->setEconomyTierFactorsBp([18000, 17000, 16000, 15000, 14000, 13000, 12000, 11000]);

        // Scotland declares four tiers. Tier 4 must read ROW 4, not the bottom row of the
        // eight-rung table — the convention GameConfig::getNpcClubBalanceRangeForTier() was
        // aligned to when scaleTierToEight() was removed.
        $resolver = $this->resolver($config, $this->starterConfig(), [
            'EN' => $this->profile('EN', isBaseline: true),
            'SC' => $this->profile('SC', tierCount: 4),
        ]);

        self::assertSame(15000, $resolver->resolve('SC', 4)->tierFactorBp);
        self::assertNotSame(11000, $resolver->resolve('SC', 4)->tierFactorBp);
    }

    public function testATierDeeperThanTheCountryHasClampsToItsBottomDivision(): void
    {
        $config = (new GameConfig())
            ->setEconomyTierFactorsBp([18000, 17000, 16000, 15000, 14000, 13000, 12000, 11000]);

        $resolver = $this->resolver($config, $this->starterConfig(), [
            'EN' => $this->profile('EN', isBaseline: true),
            'SC' => $this->profile('SC', tierCount: 4),
        ]);

        // Asking a 4-tier country for tier 7 is a caller bug, but a null row would surface
        // downstream as a wage of zero rather than as an error.
        $scale = $resolver->resolve('SC', 7);

        self::assertSame(4, $scale->tier);
        self::assertSame(15000, $scale->tierFactorBp);
    }

    // ── The wage scale ────────────────────────────────────────────────────

    public function testTheWageScaleMakesTheReferenceSquadCostItsShareOfTurnover(): void
    {
        // The defining property of the turnover model: S_tier is whatever makes a reference
        // squad for the tier cost `ratio x (1 - staffShare)` of that tier's turnover over a
        // season. Asserted by reconstructing the squad bill from the scale and comparing it
        // back to the budget, so the test checks the DEFINITION rather than restating the
        // implementation's arithmetic.
        $turnover = 100_000_000; // £1m, tier 1
        $config   = (new GameConfig())
            ->setEconomyBaselineTurnoverPence([$turnover, 0, 0, 0, 0, 0, 0, 0])
            ->setEconomyWageToTurnoverRatioBp(6000)
            ->setEconomyStaffWageShareBp(2500)
            ->setEconomyWeeksPerSeason(46)
            ->setEconomyWageCurveExponentBp(25000);

        $starter = $this->starterConfig(
            bands:  ['EN' => ['1' => ['min' => 40, 'max' => 90]]],
            squads: ['1' => ['playerMin' => 20, 'playerMax' => 20]],
        );

        $resolver = $this->resolver($config, $starter, ['EN' => $this->profile('EN', isBaseline: true)]);
        $scale    = $resolver->resolve('EN', 1);

        self::assertTrue($scale->isResolvable);

        // INTEGER ABILITIES, matching the resolver. `Player::$currentAbility` is an int, so a
        // reference squad of continuous abilities would calibrate S_tier against players who
        // cannot exist — the error is invisible on a wide band and 2.5% on England's tier-8
        // span of 2-7, which is how `app:economy:wage-table`'s self-consistency check caught
        // it. This reconstruction has to round the same way or it tests nothing.
        $k     = 2.5;
        $sigma = 0.0;
        for ($i = 0; $i < 20; $i++) {
            $sigma += ((int) round(40 + 50 * $i / 19)) ** $k;
        }

        $bill   = (int) round(46 * $sigma * $scale->wageScaleMicroPence / EconomyScaleResolver::WAGE_SCALE_UNIT);
        $budget = intdiv(intdiv($turnover * 6000, 10000) * 7500, 10000);

        // Within a penny per player: the scale rounds once, to micro-pence.
        self::assertLessThanOrEqual(20, abs($bill - $budget), "squad bill {$bill} vs budget {$budget}");
    }

    public function testTheWageScaleCarriesSubPennyPrecision(): void
    {
        // A bottom division's S_tier is a fraction of a penny per unit of ability^k. Rounded to
        // pence it would quantise every wage in the division to a handful of values, and at a
        // small country factor it would round to zero and make every player free.
        $config = (new GameConfig())
            ->setEconomyBaselineTurnoverPence([0, 0, 0, 0, 0, 0, 0, 27_200_000]);

        $starter = $this->starterConfig(
            bands:  ['EN' => ['8' => ['min' => 2, 'max' => 7]]],
            squads: ['8' => ['playerMin' => 20, 'playerMax' => 24]],
        );

        $resolver = $this->resolver($config, $starter, ['EN' => $this->profile('EN', isBaseline: true)]);
        $scale    = $resolver->resolve('EN', 8);

        self::assertTrue($scale->isResolvable);
        self::assertGreaterThan(0, $scale->wageScaleMicroPence);
        // The point of the unit: the figure is NOT a whole number of pence.
        self::assertLessThan(EconomyScaleResolver::WAGE_SCALE_UNIT * 1000, $scale->wageScaleMicroPence);
    }

    public function testATransposedAbilityBandIsNormalisedRatherThanInverted(): void
    {
        // min and max are authored independently in the admin, so a swapped pair is a real
        // state of the row — same reasoning as ScoutWageCalculator::weeklyWageFor().
        $config = (new GameConfig())->setEconomyBaselineTurnoverPence([100_000_000, 0, 0, 0, 0, 0, 0, 0]);

        $right = $this->resolver($config, $this->starterConfig(
            bands:  ['EN' => ['1' => ['min' => 40, 'max' => 90]]],
            squads: ['1' => ['playerMin' => 20, 'playerMax' => 20]],
        ), ['EN' => $this->profile('EN', isBaseline: true)])->resolve('EN', 1);

        $swapped = $this->resolver($config, $this->starterConfig(
            bands:  ['EN' => ['1' => ['min' => 90, 'max' => 40]]],
            squads: ['1' => ['playerMin' => 20, 'playerMax' => 20]],
        ), ['EN' => $this->profile('EN', isBaseline: true)])->resolve('EN', 1);

        self::assertSame($right->wageScaleMicroPence, $swapped->wageScaleMicroPence);
    }

    // ── Inflation ─────────────────────────────────────────────────────────

    public function testSeasonOneIsAlwaysTheBaseYear(): void
    {
        $resolver = $this->resolver(
            (new GameConfig())->setInflationRatePerSeasonBp(500),
            $this->starterConfig(),
            [],
        );

        self::assertSame(10000, $resolver->inflationIndexBp(1));
        self::assertSame(10000, $resolver->inflationIndexBp(0), 'a season below 1 clamps to the base year');
    }

    public function testTheIndexCompoundsPerSeason(): void
    {
        $resolver = $this->resolver(
            (new GameConfig())->setInflationRatePerSeasonBp(1000), // 10% a season
            $this->starterConfig(),
            [],
        );

        self::assertSame(11000, $resolver->inflationIndexBp(2));
        self::assertSame(12100, $resolver->inflationIndexBp(3));
        self::assertSame(13310, $resolver->inflationIndexBp(4));
    }

    public function testAnAbsurdSeasonNumberIsBoundedRatherThanSpinning(): void
    {
        // The season arrives from a device-mirrored column, so it is bounded rather than
        // trusted — a forged 100,000 must not become a 100,000-iteration loop.
        $resolver = $this->resolver(
            (new GameConfig())->setInflationRatePerSeasonBp(1000),
            $this->starterConfig(),
            [],
        );

        self::assertSame(
            $resolver->inflationIndexBp(200),
            $resolver->inflationIndexBp(100_000),
        );
    }

    public function testDeflationIsRefusedAtTheSetter(): void
    {
        // A negative rate makes a long save's ceiling approach zero, so the owner who has
        // played longest could invest least. Floored rather than supported — see the setter.
        self::assertSame(0, (new GameConfig())->setInflationRatePerSeasonBp(-500)->getInflationRatePerSeasonBp());
    }

    // ── Guards on the config setters ──────────────────────────────────────

    public function testAShortTierListIsPaddedToEight(): void
    {
        // These are read by index; a short list would resolve a deep country's lower tiers to
        // null mid-chain, which surfaces as a wage of zero rather than an exception.
        $config = (new GameConfig())->setEconomyTierFactorsBp([12000, 11000]);

        self::assertCount(8, $config->getEconomyTierFactorsBp());
        self::assertSame([12000, 11000, 10000, 10000, 10000, 10000, 10000, 10000], $config->getEconomyTierFactorsBp());
    }

    public function testALongTierListIsTruncatedToEight(): void
    {
        $config = (new GameConfig())->setEconomyTierFactorsBp(array_fill(0, 12, 9000));

        self::assertCount(8, $config->getEconomyTierFactorsBp());
    }

    public function testAFullStaffShareCannotMakeEveryPlayerFree(): void
    {
        self::assertSame(9999, (new GameConfig())->setEconomyStaffWageShareBp(10000)->getEconomyStaffWageShareBp());
        self::assertSame(9999, (new GameConfig())->setEconomyStaffWageShareBp(50000)->getEconomyStaffWageShareBp());
    }

    public function testAZeroCurveExponentIsRefused(): void
    {
        // ability^0 is 1 for every ability, which is not a curve — every player in a division
        // would cost the same.
        self::assertSame(1, (new GameConfig())->setEconomyWageCurveExponentBp(0)->getEconomyWageCurveExponentBp());
    }

    public function testAZeroCountryFactorIsRefused(): void
    {
        // Zero would resolve every wage, balance and ceiling in the country to nothing — a
        // state with no legitimate meaning that reads as "the economy is off here".
        $profile = new CountryProfile('ZZ', 'Nowhere');
        $profile->setEconomyFactorBp(0);

        self::assertSame(1, $profile->getEconomyFactorBp());
    }

    // ── Memoisation ───────────────────────────────────────────────────────

    public function testTheCountryProfileIsLookedUpOncePerRequest(): void
    {
        // A world-pack build resolves the same country and tier once per club. Nothing here
        // writes, so caching is safe — and without it the baseline lookup is a query per club.
        $profiles = $this->createMock(CountryProfileRepository::class);
        $profiles->expects(self::exactly(2))   // once for the baseline, once for EN itself
            ->method('findByCode')
            ->willReturn($this->profile('EN', isBaseline: true));
        $profiles->expects(self::once())
            ->method('findEconomyBaseline')
            ->willReturn($this->profile('EN', isBaseline: true));

        $resolver = new EconomyScaleResolver(
            $this->gameConfigRepo(new GameConfig()),
            $this->starterConfigRepo($this->starterConfig()),
            $profiles,
        );

        for ($i = 0; $i < 25; $i++) {
            $resolver->resolve('EN', 3);
        }
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /** @param array<string, CountryProfile> $profiles */
    private function resolver(GameConfig $config, StarterConfig $starter, array $profiles): EconomyScaleResolver
    {
        $repo = $this->createStub(CountryProfileRepository::class);
        $repo->method('findByCode')->willReturnCallback(
            static fn (string $code) => $profiles[strtoupper(trim($code))] ?? null,
        );
        $repo->method('findEconomyBaseline')->willReturnCallback(
            static function () use ($profiles) {
                foreach ($profiles as $profile) {
                    if ($profile->isEconomyBaseline()) {
                        return $profile;
                    }
                }
                return null;
            },
        );

        return new EconomyScaleResolver(
            $this->gameConfigRepo($config),
            $this->starterConfigRepo($starter),
            $repo,
        );
    }

    private function gameConfigRepo(GameConfig $config): GameConfigRepository
    {
        $repo = $this->createStub(GameConfigRepository::class);
        $repo->method('getConfig')->willReturn($config);

        return $repo;
    }

    private function starterConfigRepo(StarterConfig $config): StarterConfigRepository
    {
        $repo = $this->createStub(StarterConfigRepository::class);
        $repo->method('getConfig')->willReturn($config);

        return $repo;
    }

    /**
     * @param array<string, array<string, array{min:int,max:int}>>|null $bands
     * @param array<string, array<string, int>>|null                    $squads
     */
    private function starterConfig(?array $bands = null, ?array $squads = null): StarterConfig
    {
        $config = new StarterConfig();

        if ($bands !== null) {
            $config->setLeagueAbilityRanges($bands);
        }
        if ($squads !== null) {
            $config->setNpcSquadConfig($squads);
        }

        return $config;
    }

    private function profile(
        string $code,
        int $factorBp = 10000,
        bool $isBaseline = false,
        int $tierCount = 8,
    ): CountryProfile {
        $profile = new CountryProfile($code, $code);
        $profile->setEconomyFactorBp($factorBp);
        $profile->setIsEconomyBaseline($isBaseline);
        $profile->setLeagueTierCount($tierCount);

        return $profile;
    }
}
