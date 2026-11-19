<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\GameConfig;
use App\Entity\PoolConfig;
use App\Entity\Location\CountryProfile;
use App\Entity\StarterConfig;
use App\Enum\PlayerPosition;
use App\Repository\GameConfigRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\StarterConfigRepository;
use App\Service\Economy\EconomyScaleResolver;
use App\Tests\Support\ReferenceSquadDoubles;
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

        // ⚠️ RECONSTRUCTED FROM THE RESOLVER'S OWN REFERENCE SQUAD, not from the ability band.
        // Until 2026-11-19 this loop spread the band 40-90 across twenty integers and summed
        // that — and it passed, because the resolver was doing the same thing. Both were
        // wrong: the band is a POTENTIAL range and a wage is paid on current ability, so the
        // scale was calibrated against players nobody would ever pay. Re-deriving the squad
        // here would pin the old calibration rather than the property this test is named
        // after; `referenceSquadAbilities()` is public precisely so the definition has one
        // statement. What is still asserted, and all that should be, is the DEFINITION: the
        // squad S_tier was calibrated against costs the configured share of turnover.
        $k     = 2.5;
        $sigma = 0.0;
        foreach ($resolver->referenceSquadAbilities('EN', 1) as $ability) {
            $sigma += $ability ** $k;
        }

        // And the squad is nothing like the band it was derived from: the top member's
        // POTENTIAL rung is 90 and his expected CURRENT ability is under 50.
        self::assertLessThan(50, max($resolver->referenceSquadAbilities('EN', 1)));

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

        // The point of the unit: the figure is NOT a whole number of pence, so rounding it to
        // pence here would quantise the division's wages.
        //
        // ⚠️ THIS USED TO BE `assertLessThan(WAGE_SCALE_UNIT * 1000, ...)` — an upper bound of
        // 1000 pence, which was a proxy for "small" and not the property named in the test.
        // It went red on 2026-11-19 when the reference squad stopped being priced at the
        // authored POTENTIAL band and started being priced at the current ability the
        // generator actually produces: S_tier at this tier rose about fourfold, as every
        // S_tier did, and the bound was pinning the old calibration rather than the precision.
        self::assertNotSame(
            0,
            $scale->wageScaleMicroPence % EconomyScaleResolver::WAGE_SCALE_UNIT,
            'S_tier landed on a whole number of pence — the sub-penny headroom is doing nothing',
        );
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
            ReferenceSquadDoubles::poolConfigRepository(),
            ReferenceSquadDoubles::playerGeneration(),
        );

        for ($i = 0; $i < 25; $i++) {
            $resolver->resolve('EN', 3);
        }
    }

    // ── The reference squad: expected CURRENT ability, not the authored band ──

    /**
     * S_TIER, PINNED AGAINST A VALUE A HUMAN CAN CHECK WITHOUT RUNNING THE GENERATOR.
     *
     * The reference squad is deliberately collapsed to ONE member, ONE age and ONE position,
     * because the shipped shape — 22 to 33 potential rungs averaged over fifteen ages and four
     * positions — is correct but not hand-checkable, and a pin nobody can verify is a pin
     * nobody will fix. Everything below is arithmetic, in the order the code does it:
     *
     *   potential            the band is 100-100 and the squad size is 1, so the single
     *                        member's potential rung is 100.
     *   abilityTarget(26)    age 26 falls in the >=26 maturation band, 0.85-1.00, midpoint
     *                        0.925. It is outside the 18-23 prodigy window, so no prodigy
     *                        weighting; it is at or over the youth ceiling age of 20, so no
     *                        ceiling. = 0.925
     *   cap                  floor(0.925 x 100)                                        = 92
     *   pace       MID 0.30-0.70 -> lo max(1, floor(27.6)) = 27, hi ceil(64.4) = 65, mid = 46
     *   technical  MID 0.50-1.00 -> lo floor(46) = 46,        hi ceil(92) = 92,    mid = 69
     *   vision     MID 0.50-1.00 -> same as technical                                  = 69
     *   height               midpoint of 163-203, no GK bonus                         = 183
     *   weight               71 + floor(normalise(183,163,211) x 15) = 71 + floor(6.25) = 77
     *   power                ceil((0.4380631 x 0.6 + (13.650+13.450)/40 x 0.4) x 100)  = 54
     *   stamina              ceil((0.4786036 x 0.5 + (13.650+10.750+9.800)/60 x 0.5) x 100) = 53
     *   heart                round((11.900+13.650+10.750)/60 x 100)                    = 61
     *   currentAbility       (46+69+69+54+53+61)/6 = 352/6                      = 58.666...
     *
     * The three personality-driven attributes read `expectedTraits()`, whose eight values are
     * pinned on their own in PersonalityGeneratorServiceTest — so this stays arithmetic rather
     * than becoming a second assertion about the mould table.
     *
     * Then, with turnover £1,000,000, wage:turnover 60%, staff share 25% and 46 weeks:
     *
     *   playerBudget = 100,000,000 x 0.60 x 0.75                   = 45,000,000 pence
     *   sigma        = (352/6)^2.5                                 ~ 26,362.04
     *   S_tier       = 45,000,000 x 10^6 / (46 x sigma)            ~ 37,111,000 micro-pence
     *                                                              ~ 37.11 pence
     *
     * The OLD calibration priced that member at his potential of 100, giving sigma = 100^2.5 =
     * 100,000 and S_tier ~ 9.78 pence — 3.8x lower, which is the whole defect in one number.
     */
    public function testSTierIsPinnedToAHandComputedReferenceSquad(): void
    {
        $pool = (new PoolConfig())
            ->setPlayerFirstTeamAgeMin(26)
            ->setPlayerAgeMax(26)
            ->setPositionWeightGk(0)
            ->setPositionWeightDef(0)
            ->setPositionWeightMid(1)
            ->setPositionWeightAtt(0);

        $generation = ReferenceSquadDoubles::playerGeneration($pool);

        // The ability chain, asserted exactly rather than approximately: every step above is
        // integer arithmetic until the final division by six.
        self::assertSame(
            352 / 6,
            $generation->expectedCurrentAbility(100, 26, PlayerPosition::MIDFIELDER),
            'the expected-ability chain moved — walk the derivation in this docblock before changing the number',
        );

        $config = (new GameConfig())
            ->setEconomyBaselineTurnoverPence([100_000_000, 0, 0, 0, 0, 0, 0, 0]);

        $resolver = new EconomyScaleResolver(
            $this->gameConfigRepo($config),
            $this->starterConfigRepo($this->starterConfig(
                ['EN' => ['1' => ['min' => 100, 'max' => 100]]],
                ['1' => ['playerMin' => 1, 'playerMax' => 1]],
            )),
            $this->profileRepo(['EN' => $this->profile('EN', isBaseline: true)]),
            ReferenceSquadDoubles::poolConfigRepository($pool),
            $generation,
        );

        $expectedSigma = (352 / 6) ** 2.5;
        $expectedScale = (int) round((45_000_000 * EconomyScaleResolver::WAGE_SCALE_UNIT) / (46 * $expectedSigma));

        self::assertSame($expectedScale, $resolver->resolve('EN', 1)->wageScaleMicroPence);

        // And in pence, so the figure is readable without recomputing the power.
        self::assertSame(37.11, round($expectedScale / EconomyScaleResolver::WAGE_SCALE_UNIT, 2));

        // The old calibration, for the record: the same member priced at his POTENTIAL.
        $onPotential = (int) round((45_000_000 * EconomyScaleResolver::WAGE_SCALE_UNIT) / (46 * 100 ** 2.5));
        self::assertSame(9.78, round($onPotential / EconomyScaleResolver::WAGE_SCALE_UNIT, 2));
        self::assertGreaterThan($onPotential * 3, $expectedScale);
    }

    /**
     * THE REFERENCE SQUAD MUST NOT CONTAIN A COIN FLIP.
     *
     * `S_tier` is baked into generated rows that outlive the request and is published to the
     * device, so two calls that disagree would price two clubs in the same division
     * differently for no reason. The risk is real rather than theoretical: the chain now walks
     * `PlayerGenerationService`, every other path through which draws — the 5% prodigy branch,
     * the maturation band, the six attributes, the physique and the whole personality matrix.
     * All of them are replaced by expectations, and nothing in the chain may reach
     * `random_int()` or `mt_rand()`.
     *
     * Asserted by re-resolving on FRESH resolvers, so the per-request memoisation cannot make
     * a random chain look stable, with the Mersenne Twister re-seeded differently each time:
     * any `mt_rand()` in the chain diverges immediately, and a `random_int()` diverges with
     * overwhelming probability across this many repeats.
     */
    public function testTheReferenceSquadIsDeterministic(): void
    {
        $bands  = ['EN' => ['1' => ['min' => 40, 'max' => 90], '8' => ['min' => 2, 'max' => 7]]];
        $squads = ['1' => ['playerMin' => 32, 'playerMax' => 35], '8' => ['playerMin' => 20, 'playerMax' => 24]];

        $first = null;

        for ($i = 0; $i < 40; $i++) {
            mt_srand($i * 7919 + 1);

            $resolver = new EconomyScaleResolver(
                $this->gameConfigRepo(
                    (new GameConfig())->setEconomyBaselineTurnoverPence(
                        [33_100_000_000, 0, 0, 0, 0, 0, 0, 2_150_000_000],
                    ),
                ),
                $this->starterConfigRepo($this->starterConfig($bands, $squads)),
                $this->profileRepo(['EN' => $this->profile('EN', isBaseline: true)]),
                ReferenceSquadDoubles::poolConfigRepository(),
                ReferenceSquadDoubles::playerGeneration(),
            );

            $observed = [
                'squad1' => $resolver->referenceSquadAbilities('EN', 1),
                'squad8' => $resolver->referenceSquadAbilities('EN', 8),
                'scale1' => $resolver->resolve('EN', 1)->wageScaleMicroPence,
                'scale8' => $resolver->resolve('EN', 8)->wageScaleMicroPence,
            ];

            $first ??= $observed;

            self::assertSame($first, $observed, "run {$i} disagreed with the first — something in the chain is drawing");
        }

        mt_srand();

        self::assertCount(33, $first['squad1']);
        self::assertCount(22, $first['squad8']);
    }

    /**
     * EVERY MEMBER IS BELOW HIS OWN POTENTIAL RUNG, AND THAT IS THE POINT.
     *
     * The authored band is a potential range; current ability is derived strictly below it. If
     * a member ever came out at or above his rung the calibration would be back to pricing
     * potentials, which is the defect this replaced — and it would do it silently, because
     * S_tier would merely be a bit low again.
     */
    public function testEveryReferenceMemberSitsBelowItsPotentialRung(): void
    {
        $resolver = new EconomyScaleResolver(
            $this->gameConfigRepo((new GameConfig())->setEconomyBaselineTurnoverPence(
                [33_100_000_000, 0, 0, 0, 0, 0, 0, 2_150_000_000],
            )),
            $this->starterConfigRepo($this->starterConfig(
                ['EN' => ['1' => ['min' => 40, 'max' => 90]]],
                ['1' => ['playerMin' => 32, 'playerMax' => 35]],
            )),
            $this->profileRepo(['EN' => $this->profile('EN', isBaseline: true)]),
            ReferenceSquadDoubles::poolConfigRepository(),
            ReferenceSquadDoubles::playerGeneration(),
        );

        $squad = $resolver->referenceSquadAbilities('EN', 1);
        $span  = 90 - 40;

        foreach ($squad as $i => $ability) {
            $rung = (int) round(40 + $span * $i / (count($squad) - 1));

            self::assertGreaterThan(0.0, $ability);
            self::assertLessThan($rung, $ability, "member {$i} is priced at or above its potential rung {$rung}");
        }
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /**
     * @param array<string, CountryProfile> $profiles
     */
    private function profileRepo(array $profiles): CountryProfileRepository
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

        return $repo;
    }


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
            ReferenceSquadDoubles::poolConfigRepository(),
            ReferenceSquadDoubles::playerGeneration(),
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
