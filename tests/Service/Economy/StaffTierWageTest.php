<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\GameConfig;
use App\Entity\StarterConfig;
use App\Enum\StaffRole;
use App\Repository\GameConfigRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\StarterConfigRepository;
use App\Service\Economy\EconomyScaleResolver;
use App\Service\Economy\TierBandScaleResolver;
use App\Service\Economy\WageModelResolver;
use App\Tests\Support\ReferenceSquadDoubles;
use PHPUnit\Framework\TestCase;

/**
 * Staff pay scales with the club's tier under the `legacy` model.
 *
 * The defect this pins: a tier-8 club paid roughly £1,000/week in staff against ~£165/week for
 * its entire squad. `economy_staff_role_bands_pence` is a single absolute per role, so it can
 * only ever be right at one tier — while a legacy PLAYER's wage scales with ability, and
 * ability is per-tier. Staff had no tier dimension at all.
 *
 * These are unit tests over stubbed config rather than integration tests, because the property
 * is arithmetic: the same role at two tiers must come out in the ratio the authored ability
 * ladder describes. `random_int` inside the band is handled by asserting on bounds rather than
 * by seeding, so nothing here depends on the RNG.
 */
class StaffTierWageTest extends TestCase
{
    private const COUNTRY = 'EN';

    /** Ability is pinned at 1 so `staffMultiplier` is the same constant on both sides. */
    private const ABILITY = 1;

    /** Collapsed role bands, so every `random_int` draw has exactly one outcome. */
    private const COACH_BAND    = 8_000;
    private const MANAGER_BAND  = 30_000;
    private const CHAIRMAN_BAND = 60_000;

    /**
     * The headline: the same role costs strictly less at a lower tier, in the authored ratio.
     *
     * Deterministic, not sampled. Every role band is collapsed to a single value by
     * `gameConfig()`, so `random_int($min, $max)` has exactly one outcome and the figure can be
     * asserted exactly — a band spanning tens of thousands of integers cannot be pinned by
     * sampling, and a test that tried would be flaky in a way that looks like a real failure.
     */
    public function testTheSameRoleCostsLessAtALowerTier(): void
    {
        $config   = $this->gameConfig();
        $resolver = $this->resolver($config, $this->starterConfig());

        $top    = $resolver->staffWeeklyPence(StaffRole::CHAIRMAN, self::ABILITY, self::COUNTRY, 1, $config);
        $bottom = $resolver->staffWeeklyPence(StaffRole::CHAIRMAN, self::ABILITY, self::COUNTRY, 8, $config);

        self::assertGreaterThan($bottom, $top, 'a tier-8 chairman must cost less than a tier-1 one');

        // The ladder: EN midpoints are 65 at tier 1 and 4.5 at tier 8, so the band is scaled by
        // 4.5/65 before the (unchanged) ability multiplier is applied to it.
        $multiplier = $resolver->multipliersFor(self::ABILITY, $config)['staff'];
        $expected   = (int) ((int) round(self::CHAIRMAN_BAND * (4.5 / 65)) * $multiplier);

        self::assertSame($expected, $bottom);
        self::assertSame((int) (self::CHAIRMAN_BAND * $multiplier), $top, 'tier 1 is the anchor and is never scaled');
    }

    /**
     * The ladder is monotonic down the pyramid.
     *
     * Worth pinning separately: the ladder comes from a hand-edited admin grid, and one that
     * was merely "lower at the bottom" could still invert in the middle unseen.
     */
    public function testPayFallsMonotonicallyDownTheTiers(): void
    {
        $config   = $this->gameConfig();
        $resolver = $this->resolver($config, $this->starterConfig());

        $previous = PHP_INT_MAX;
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $tier) {
            $pence = $resolver->staffWeeklyPence(StaffRole::MANAGER, self::ABILITY, self::COUNTRY, $tier, $config);
            self::assertLessThanOrEqual($previous, $pence, "tier {$tier} pays more than the tier above it");
            $previous = $pence;
        }
    }

    /**
     * THE COMPATIBILITY SWITCH. With it off every figure is what it was before the ladder,
     * which is what makes this reversible on a box that wants the old numbers back.
     */
    public function testWithScalingOffTheFigureIsTheUnscaledBand(): void
    {
        $config = $this->gameConfig();
        $config->setEconomyStaffTierScalingEnabled(false);

        $resolver = $this->resolver($config, $this->starterConfig());

        $top    = $resolver->staffWeeklyPence(StaffRole::CHAIRMAN, self::ABILITY, self::COUNTRY, 1, $config);
        $bottom = $resolver->staffWeeklyPence(StaffRole::CHAIRMAN, self::ABILITY, self::COUNTRY, 8, $config);

        self::assertSame($top, $bottom, 'tier must not change the figure when scaling is off');
    }

    /** No country or tier — a shared-pool staff member — is never scaled. */
    public function testAPoolStaffMemberWithNoTierIsUnscaled(): void
    {
        $config   = $this->gameConfig();
        $resolver = $this->resolver($config, $this->starterConfig());

        $pooled = $resolver->staffWeeklyPence(StaffRole::CHAIRMAN, self::ABILITY, '', 0, $config);
        $anchor = $resolver->staffWeeklyPence(StaffRole::CHAIRMAN, self::ABILITY, self::COUNTRY, 1, $config);

        self::assertSame($anchor, $pooled);
    }

    /**
     * The floor stops the bottom of the band landing near £1.38/week, which reads as broken
     * rather than as cheap.
     *
     * Note what it floors: the BAND, before the ability multiplier. That is why the expected
     * figure here is `floor x multiplier` and not the floor itself — stated explicitly because
     * "a £50 floor produced £30" looks wrong until you know which side of the multiply it sits.
     */
    public function testTheFloorHoldsTheBottomOfTheBandUp(): void
    {
        $config = $this->gameConfig();
        $config->setEconomyStaffTierFloorPence(5_000);

        $resolver = $this->resolver($config, $this->starterConfig());

        $pence      = $resolver->staffWeeklyPence(StaffRole::COACH, self::ABILITY, self::COUNTRY, 8, $config);
        $multiplier = $resolver->multipliersFor(self::ABILITY, $config)['staff'];

        self::assertSame((int) (5_000 * $multiplier), $pence);
    }

    /** An unauthored country falls through to the const ladder rather than collapsing to zero. */
    public function testAnUnauthoredCountryStillScalesAndNeverReachesZero(): void
    {
        $config   = $this->gameConfig();
        $resolver = $this->resolver($config, new StarterConfig());

        $pence = $resolver->staffWeeklyPence(StaffRole::MANAGER, self::ABILITY, 'ZZ', 8, $config);

        self::assertGreaterThan(0, $pence, 'a tier must never price its staff at nothing');
    }

    /**
     * Entity defaults, except every role band is collapsed to a single value so
     * `random_int($min, $max)` is deterministic and the arithmetic can be asserted exactly.
     *
     * The multipliers are deliberately NOT pinned here — the tests read them back off the
     * resolver, so this file keeps passing when an operator re-authors `wageMultiplierTiers`
     * (the entity default and the dev box already disagree: 0.6/1.0/2.0/4.0 against
     * 2.0/4.0/8.0/12.0). What is under test is the TIER ladder, not the ability curve.
     */
    private function gameConfig(): GameConfig
    {
        $config = new GameConfig();
        $config->setEconomyStaffRoleBandsPence([
            'coach'                => [self::COACH_BAND, self::COACH_BAND],
            'manager'              => [self::MANAGER_BAND, self::MANAGER_BAND],
            'director_of_football' => [self::MANAGER_BAND, self::MANAGER_BAND],
            'facility_manager'     => [self::COACH_BAND, self::COACH_BAND],
            'chairman'             => [self::CHAIRMAN_BAND, self::CHAIRMAN_BAND],
        ]);

        return $config;
    }

    /** EN's real authored ladder, midpoints 65 at tier 1 down to 4.5 at tier 8. */
    private function starterConfig(): StarterConfig
    {
        $config = new StarterConfig();
        $config->setLeagueAbilityRanges([
            self::COUNTRY => [
                '1' => ['min' => 40, 'max' => 90],
                '2' => ['min' => 30, 'max' => 60],
                '3' => ['min' => 20, 'max' => 40],
                '4' => ['min' => 15, 'max' => 35],
                '5' => ['min' => 10, 'max' => 25],
                '6' => ['min' => 10, 'max' => 15],
                '7' => ['min' => 5,  'max' => 10],
                '8' => ['min' => 2,  'max' => 7],
            ],
        ]);

        return $config;
    }

    private function resolver(GameConfig $config, StarterConfig $starter): WageModelResolver
    {
        $gameConfigRepo = $this->createStub(GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn($config);

        $starterRepo = $this->createStub(StarterConfigRepository::class);
        $starterRepo->method('getConfig')->willReturn($starter);

        return new WageModelResolver(
            $gameConfigRepo,
            new EconomyScaleResolver(
                $gameConfigRepo,
                $starterRepo,
                $this->createStub(CountryProfileRepository::class),
                ReferenceSquadDoubles::poolConfigRepository(),
                ReferenceSquadDoubles::playerGeneration(),
            ),
            new TierBandScaleResolver($starterRepo),
        );
    }
}
