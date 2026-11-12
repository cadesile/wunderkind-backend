<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\GameConfig;
use App\Entity\StarterConfig;
use App\Enum\EconomyWageModel;
use App\Repository\GameConfigRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\StarterConfigRepository;
use App\Service\Economy\EconomyScaleResolver;
use App\Service\Economy\WageModelResolver;
use PHPUnit\Framework\TestCase;

/**
 * A youth scholar must not out-earn a professional.
 *
 * THE DEFECT: `youthScholarshipWagePence` is a flat £250/week, and the turnover model makes an
 * average England tier-8 first-team wage about £127. So a thirteen-year-old was set to earn
 * nearly twice an average player, and at tier 7 about half. The legacy model never inverts, so
 * it is a consequence of the overhaul — found by the step-8 threshold survey rather than by
 * anything failing.
 *
 * TWO PROPERTIES ARE UNDER TEST AND THE SECOND MATTERS AS MUCH AS THE FIRST:
 *
 *  1. The cap bites where the flat figure is implausible — a scholar ends up below the worst
 *     first-team wage at the tier.
 *  2. **It is inert in the shipped state.** The cap ships ON at 50%, which breaks this series'
 *     own "every switch arrives in its inert position" rule, so the claim that it still changes
 *     nothing today has to be proven rather than asserted. It holds because the cap applies
 *     ONLY under the `turnover` model — not merely when a turnover table happens to be
 *     authored, which is the distinction the first implementation got wrong and the test below
 *     caught.
 *
 * The live England figures are used throughout — bands 40-90 down to 2-7 — because the
 * inversion only appears on a narrow bottom-tier band and a tidy synthetic one would hide it.
 */
class YouthScholarshipCapTest extends TestCase
{
    /** Live England bands, as exported 2026-11-12. */
    private const BANDS = [
        '1' => ['min' => 40, 'max' => 90], '2' => ['min' => 30, 'max' => 60],
        '3' => ['min' => 20, 'max' => 40], '4' => ['min' => 15, 'max' => 35],
        '5' => ['min' => 10, 'max' => 25], '6' => ['min' => 10, 'max' => 15],
        '7' => ['min' => 5,  'max' => 10], '8' => ['min' => 2,  'max' => 7],
    ];

    /** Live squad sizes. */
    private const SQUADS = [
        '1' => ['playerMin' => 32, 'playerMax' => 35], '2' => ['playerMin' => 28, 'playerMax' => 32],
        '3' => ['playerMin' => 27, 'playerMax' => 30], '4' => ['playerMin' => 25, 'playerMax' => 28],
        '5' => ['playerMin' => 25, 'playerMax' => 28], '6' => ['playerMin' => 25, 'playerMax' => 28],
        '7' => ['playerMin' => 22, 'playerMax' => 25], '8' => ['playerMin' => 20, 'playerMax' => 24],
    ];

    /** The candidate ladder from the step-5 calibration. */
    private const TURNOVER = [
        49_257_823_013, 16_225_145_609, 5_105_781_037, 2_878_038_443,
        1_111_614_720, 406_895_378, 97_642_916, 27_219_507,
    ];

    // ── It is inert in the shipped state ─────────────────────────────────

    public function testTheShippedDefaultsChangeNothing(): void
    {
        // THE PROOF THE DEFAULT IS SAFE. `legacy` plus an unauthored turnover table is what is
        // deployed, and this series' whole claim is that applying it moves no figure.
        $config   = $this->config();
        $resolver = $this->resolver($config);

        foreach (range(1, 8) as $tier) {
            self::assertSame(
                $config->getYouthScholarshipWagePence(),
                $resolver->youthScholarshipPence('EN', $tier, $config),
                "tier {$tier} must still get the flat figure",
            );
        }
    }

    public function testTheCapCannotBiteUnderTheLegacyModel(): void
    {
        // A turnover table authored but the model left on `legacy` — the half-configured state
        // EVERY operator passes through while filling the table in. The flat figure must still
        // stand, and this test is what caught the first implementation capping here: legacy
        // tier-8 wages average about £480, so £250 is already sensible and capping against a
        // band wage the world is not priced by would have made scholars absurdly cheap
        // relative to their teammates.
        $config = $this->config(turnover: self::TURNOVER);

        self::assertSame(
            25000,
            $this->resolver($config)->youthScholarshipPence('EN', 8, $config),
        );
    }

    // ── The preview asks under the model it is previewing ────────────────

    public function testThePreviewSeesTheCapWhileTheLiveModelIsStillLegacy(): void
    {
        // THE DEFECT THIS PINS, found by running the real gate against the dev-DB clone rather
        // than by reading the code. `app:economy:wage-table` prices an entire table through
        // the turnover model while the live setting is still `legacy` — that IS its job, since
        // the runbook says get the gate to exit 0 BEFORE flipping the switch. It asked for the
        // scholarship with youthScholarshipPence(), got the flat £250 because the live model
        // was legacy, compared it against a turnover wage of £126.94, and reported an
        // inversion.
        //
        // WHY THAT IS WORSE THAN A COSMETIC BUG: the problem could not be cleared by any
        // authoring action. Flipping the model makes the cap bite and the complaint disappear
        // — so the gate was blocking the flip on a problem that only existed until the flip
        // happened, and an operator following the runbook would never get to zero. Measured on
        // the clone: 16 problems under `legacy`, 14 under `turnover`, the diff being exactly
        // the two scholarship lines.
        $config = $this->config(turnover: self::TURNOVER);   // table authored, model LEGACY

        $resolver = $this->resolver($config);

        // The live answer is unchanged — that is the inertness guarantee, still intact.
        self::assertSame(
            25000,
            $resolver->youthScholarshipPence('EN', 8, $config),
            'the live figure must still be the flat one',
        );

        // The preview's answer is the capped one, because that is what turnover would pay.
        $previewed = $resolver->youthScholarshipPenceUnder(
            EconomyWageModel::TURNOVER,
            'EN',
            8,
            $config,
        );

        self::assertLessThan(25000, $previewed, 'the preview must see the cap bite');
        self::assertGreaterThanOrEqual(1, $previewed, 'and never reach zero');
    }

    public function testThePreviewedFigureIsBelowAnAverageFirstTeamWage(): void
    {
        // The property the gate's check is actually asserting, stated directly so it cannot
        // regress through the command. A scholar must not out-earn an average professional in
        // the state being previewed.
        $config   = $this->config(turnover: self::TURNOVER);   // model still LEGACY
        $resolver = $this->resolver($config);

        $previewed = $resolver->youthScholarshipPenceUnder(EconomyWageModel::TURNOVER, 'EN', 8, $config);
        $midWage   = $resolver->turnoverPlayerPence(4, 'EN', 8, 50, $config);   // band 2-7, mid 4

        self::assertNotNull($midWage, 'the tier must be priceable, or this test proves nothing');
        self::assertLessThan($midWage, $previewed);
    }

    public function testAskingUnderLegacyExplicitlyIsTheFlatFigure(): void
    {
        // The other direction, so the new method cannot quietly become "always cap".
        $config = $this->config(turnover: self::TURNOVER, model: EconomyWageModel::TURNOVER);

        self::assertSame(
            25000,
            $this->resolver($config)->youthScholarshipPenceUnder(EconomyWageModel::LEGACY, 'EN', 8, $config),
            'a caller naming legacy gets legacy, whatever is live',
        );
    }

    public function testTheTwoEntryPointsAgreeWhenTheModelMatchesTheLiveOne(): void
    {
        // The delegation is the whole reason there is still one implementation; if these ever
        // diverge, the cap has been reimplemented in one of them.
        foreach ([EconomyWageModel::LEGACY, EconomyWageModel::TURNOVER] as $model) {
            $config   = $this->config(turnover: self::TURNOVER, model: $model);
            $resolver = $this->resolver($config);

            foreach (range(1, 8) as $tier) {
                self::assertSame(
                    $resolver->youthScholarshipPence('EN', $tier, $config),
                    $resolver->youthScholarshipPenceUnder($model, 'EN', $tier, $config),
                    "{$model->value} tier {$tier}",
                );
            }
        }
    }

    public function testAnUnauthoredTierStillGetsTheFlatFigure(): void
    {
        // The model on, the table only half filled. A tier with no turnover cannot be priced,
        // so there is nothing to cap against.
        $config = $this->config(
            turnover: [49_257_823_013, 0, 0, 0, 0, 0, 0, 0],
            model: EconomyWageModel::TURNOVER,
        );

        self::assertSame(25000, $this->resolver($config)->youthScholarshipPence('EN', 8, $config));
    }

    public function testACapOfZeroMeansNoCap(): void
    {
        // Kept for an operator who wants the flat figure whatever happens — and the same
        // zero-means-off idiom as max_enrichment_requests_per_user.
        $config = $this->config(
            turnover: self::TURNOVER,
            model: EconomyWageModel::TURNOVER,
            capBp: 0,
        );

        self::assertSame(25000, $this->resolver($config)->youthScholarshipPence('EN', 8, $config));
    }

    // ── It fixes the inversion ───────────────────────────────────────────

    public function testAScholarNeverOutEarnsAnAverageProfessionalAtAnyTier(): void
    {
        // THE ONE INVARIANT THE MECHANISM PROMISES, asserted across every tier rather than only
        // the ones that were broken — a fix that only works where the bug was noticed is not a
        // fix.
        //
        // AGAINST THE AVERAGE, NOT THE CHEAPEST, and this test was rewritten to say so. The
        // original asserted "never more than the WORST first-team wage", which sounds stricter
        // and is actually unachievable: England tier 8's cheapest professional earns £12.84/wk,
        // because ability 2 at the bottom of a 2-7 band through a 2.5 exponent is a rounding
        // error. Half of £12.84 is £6.42, so the old rule did not deliver a cheap scholar, it
        // delivered a free one — which `youthScholarshipPence()` then had to floor at 1p.
        $config   = $this->config(turnover: self::TURNOVER, model: EconomyWageModel::TURNOVER);
        $resolver = $this->resolver($config);

        foreach (range(1, 8) as $tier) {
            $average = $resolver->averageFirstTeamWagePence('EN', $tier, 50, $config);
            $scholar = $resolver->youthScholarshipPence('EN', $tier, $config);

            self::assertNotNull($average);
            self::assertLessThanOrEqual(
                $average,
                $scholar,
                sprintf('tier %d: scholar %d must not exceed the average first-team wage %d', $tier, $scholar, $average),
            );
        }
    }

    public function testTheInvariantHoldsWithAnAnchorAndFloorAuthored(): void
    {
        // The same promise, in the configuration an operator will actually run: the anchored
        // ladder with a floor under it. The floor is the thing most likely to break this, since
        // it pushes figures UP at exactly the tiers where wages are smallest.
        $config = $this->config(
            turnover: self::TURNOVER,
            model: EconomyWageModel::TURNOVER,
        );
        $config->setEconomyYouthScholarshipTier1Pence(150000);   // £1,500
        $config->setEconomyYouthScholarshipFloorPence(2500);     // £25

        $resolver = $this->resolver($config);

        foreach (range(1, 8) as $tier) {
            $average = $resolver->averageFirstTeamWagePence('EN', $tier, 50, $config);
            $scholar = $resolver->youthScholarshipPence('EN', $tier, $config);

            self::assertNotNull($average);
            self::assertLessThanOrEqual(
                $average,
                $scholar,
                sprintf('tier %d: scholar %d vs average %d', $tier, $scholar, $average),
            );
            self::assertGreaterThanOrEqual(1, $scholar, "tier {$tier} must never be free");
        }
    }

    public function testAFloorAboveATiersCapIsClampedDownToIt(): void
    {
        // THE ORDERING THAT MAKES THE FLOOR SAFE. The floor is a preference and the cap is the
        // invariant, so the cap is applied LAST. An operator who sets an absurd floor gets a
        // capped figure, not a scholar out-earning the squad.
        $config = $this->config(turnover: self::TURNOVER, model: EconomyWageModel::TURNOVER);
        $config->setEconomyYouthScholarshipTier1Pence(150000);
        $config->setEconomyYouthScholarshipFloorPence(99_999_999);

        $resolver = $this->resolver($config);
        $average  = $resolver->averageFirstTeamWagePence('EN', 8, 50, $config);

        self::assertNotNull($average);
        self::assertSame(
            intdiv($average * 5000, 10000),
            $resolver->youthScholarshipPence('EN', 8, $config),
            'the cap, not the absurd floor',
        );
    }

    public function testTheAnchoredLadderIsTheSameProportionAtEveryUnflooredTier(): void
    {
        // THE DEFINING PROPERTY of "anchor at the top and scale down": where the floor is not
        // binding, every tier costs the same SHARE of its own average wage. That is what makes
        // one authored figure enough, and it is the thing a future "improvement" to the formula
        // would most likely break.
        $config = $this->config(turnover: self::TURNOVER, model: EconomyWageModel::TURNOVER);
        $config->setEconomyYouthScholarshipTier1Pence(150000);
        $config->setEconomyYouthScholarshipFloorPence(0);        // no floor, so pure proportion

        $resolver = $this->resolver($config);
        $shares   = [];

        foreach (range(1, 8) as $tier) {
            $average = $resolver->averageFirstTeamWagePence('EN', $tier, 50, $config);
            self::assertNotNull($average);

            // Basis points of the tier's average, which is the share being held constant.
            $shares[$tier] = intdiv($resolver->youthScholarshipPence('EN', $tier, $config) * 10000, $average);
        }

        // Integer division at the bottom of the ladder costs a basis point or two; the claim is
        // that the share is CONSTANT, not that it is bit-identical.
        foreach (range(2, 8) as $tier) {
            self::assertEqualsWithDelta(
                $shares[1],
                $shares[$tier],
                20,
                sprintf('tier %d is %d bp of its average against tier 1\'s %d bp', $tier, $shares[$tier], $shares[1]),
            );
        }
    }

    public function testTheAnchorSetsTierOneExactly(): void
    {
        // What the operator typed is what tier 1 costs — no rounding, no reinterpretation.
        $config = $this->config(turnover: self::TURNOVER, model: EconomyWageModel::TURNOVER);
        $config->setEconomyYouthScholarshipTier1Pence(150000);

        self::assertSame(150000, $this->resolver($config)->youthScholarshipPence('EN', 1, $config));
    }

    public function testTheFloorHoldsTheBottomOfTheLadderUp(): void
    {
        // The reason the floor exists, asserted as the difference it makes rather than as a
        // configuration detail: without it the bottom tiers decay into pennies.
        $base = $this->config(turnover: self::TURNOVER, model: EconomyWageModel::TURNOVER);
        $base->setEconomyYouthScholarshipTier1Pence(150000);

        $withFloor = $this->config(turnover: self::TURNOVER, model: EconomyWageModel::TURNOVER);
        $withFloor->setEconomyYouthScholarshipTier1Pence(150000);
        $withFloor->setEconomyYouthScholarshipFloorPence(2500);

        $unfloored = $this->resolver($base)->youthScholarshipPence('EN', 8, $base);
        $floored   = $this->resolver($withFloor)->youthScholarshipPence('EN', 8, $withFloor);

        self::assertLessThan(1000, $unfloored, 'a pure proportion really does collapse here');
        self::assertSame(2500, $floored);
    }

    public function testAnAnchorWithNoTurnoverAtTierOneFallsBackRatherThanGuessing(): void
    {
        // Tier 1 unauthored while a lower tier is not — a half-filled table, which is a real
        // state. There is nothing to anchor against, so the flat figure and its cap stand
        // instead of a ladder extrapolated from one rung.
        $config = $this->config(
            turnover: [0, 0, 0, 0, 0, 0, 97_642_916, 27_219_507],
            model: EconomyWageModel::TURNOVER,
        );
        $config->setEconomyYouthScholarshipTier1Pence(150000);

        $resolver = $this->resolver($config);
        $average  = $resolver->averageFirstTeamWagePence('EN', 8, 50, $config);

        self::assertNotNull($average, 'tier 8 must be priceable, or this test proves nothing');
        self::assertSame(
            min(25000, intdiv($average * 5000, 10000)),
            $resolver->youthScholarshipPence('EN', 8, $config),
        );
    }

    public function testAZeroAnchorIsTheOldBehaviourExactly(): void
    {
        // The shipped default. Zero-means-off has to mean the flat figure and its cap, or the
        // migration is not the no-op it claims to be.
        $config = $this->config(turnover: self::TURNOVER, model: EconomyWageModel::TURNOVER);
        $config->setEconomyYouthScholarshipTier1Pence(0);
        $config->setEconomyYouthScholarshipFloorPence(2500);   // ignored without an anchor

        $resolver = $this->resolver($config);
        $average  = $resolver->averageFirstTeamWagePence('EN', 8, 50, $config);

        self::assertNotNull($average);
        self::assertSame(
            min(25000, intdiv($average * 5000, 10000)),
            $resolver->youthScholarshipPence('EN', 8, $config),
            'no anchor means the flat figure capped, and the floor must not sneak in',
        );
    }

    public function testTheBottomTiersAreTheOnesThatChange(): void
    {
        // The behaviour with NO anchor authored, which is the shipped default: the cap touches
        // only the tiers where the flat figure is implausible.
        //
        // NOTE THE CLAIM THIS TEST NO LONGER MAKES. It used to be headed "the flat figure is
        // right at the top and wrong at the bottom". The first half was wrong: £250 is 0.19% of
        // an average tier-1 wage, a rounding error rather than a cheap academy. The cap fixed
        // the end that inverted; the tier-1 anchor fixes the end that was quietly negligible.
        // What survives is only that the cap alone changes nothing above tier 6.
        $config   = $this->config(turnover: self::TURNOVER, model: EconomyWageModel::TURNOVER);
        $resolver = $this->resolver($config);

        foreach ([1, 2, 3, 4, 5, 6] as $tier) {
            self::assertSame(
                25000,
                $resolver->youthScholarshipPence('EN', $tier, $config),
                "tier {$tier}'s authored figure must survive untouched",
            );
        }

        foreach ([7, 8] as $tier) {
            self::assertLessThan(
                25000,
                $resolver->youthScholarshipPence('EN', $tier, $config),
                "tier {$tier} is where the flat figure was implausible",
            );
        }
    }

    public function testTheCapIsTheConfiguredShareOfTheAverageWage(): void
    {
        $config   = $this->config(turnover: self::TURNOVER, model: EconomyWageModel::TURNOVER);
        $resolver = $this->resolver($config);

        $average = $resolver->averageFirstTeamWagePence('EN', 8, 50, $config);

        self::assertNotNull($average);
        self::assertSame(intdiv($average * 5000, 10000), $resolver->youthScholarshipPence('EN', 8, $config));
    }

    public function testAHarsherCapBitesHarder(): void
    {
        $tight = $this->config(turnover: self::TURNOVER, model: EconomyWageModel::TURNOVER, capBp: 1000);
        $loose = $this->config(turnover: self::TURNOVER, model: EconomyWageModel::TURNOVER, capBp: 9000);

        self::assertLessThan(
            $this->resolver($loose)->youthScholarshipPence('EN', 8, $loose),
            $this->resolver($tight)->youthScholarshipPence('EN', 8, $tight),
        );
    }

    public function testAScholarIsNeverFree(): void
    {
        // Floored at 1p. A tier whose worst wage rounds to nothing would otherwise make
        // scholars FREE, and free is a different mechanic from cheap — SyncService's own
        // comment on this figure notes the client guards with `?? fallback` rather than
        // falsiness, so a 0 sails straight through it.
        $config = $this->config(
            // A turnover so small the band wage is a fraction of a penny.
            turnover: [1, 1, 1, 1, 1, 1, 1, 1],
            model: EconomyWageModel::TURNOVER,
            capBp: 1,
        );

        self::assertGreaterThan(0, $this->resolver($config)->youthScholarshipPence('EN', 8, $config));
    }

    // ── Guards on the setter ─────────────────────────────────────────────

    public function testTheCapCannotExceedTheWholeWage(): void
    {
        // Above 100% the cap would permit the very inversion it exists to prevent.
        self::assertSame(10000, (new GameConfig())->setEconomyYouthScholarshipCapBp(20000)->getEconomyYouthScholarshipCapBp());
        self::assertSame(0, (new GameConfig())->setEconomyYouthScholarshipCapBp(-5)->getEconomyYouthScholarshipCapBp());
    }

    public function testTheFlatFigureIsUntouchedByAllOfThis(): void
    {
        // BACKWARDS COMPATIBILITY: `youthScholarshipWagePence` keeps its name, its type and its
        // meaning. Old builds read it and get exactly what they always did; the resolved
        // per-tier figure is a new key they do not know about.
        self::assertSame(
            GameConfig::DEFAULT_YOUTH_SCHOLARSHIP_WAGE_PENCE,
            (new GameConfig())->getYouthScholarshipWagePence(),
        );
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /** @param list<int>|null $turnover */
    private function config(
        ?array $turnover = null,
        ?EconomyWageModel $model = null,
        ?int $capBp = null,
    ): GameConfig {
        $config = new GameConfig();

        if ($turnover !== null) {
            $config->setEconomyBaselineTurnoverPence($turnover);
        }
        if ($model !== null) {
            $config->setEconomyWageModel($model);
        }
        if ($capBp !== null) {
            $config->setEconomyYouthScholarshipCapBp($capBp);
        }

        return $config;
    }

    private function resolver(GameConfig $config): WageModelResolver
    {
        $gameConfigRepo = $this->createStub(GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn($config);

        $starter = new StarterConfig();
        $starter->setLeagueAbilityRanges(['EN' => self::BANDS]);
        $starter->setNpcSquadConfig(self::SQUADS);

        $starterRepo = $this->createStub(StarterConfigRepository::class);
        $starterRepo->method('getConfig')->willReturn($starter);

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
