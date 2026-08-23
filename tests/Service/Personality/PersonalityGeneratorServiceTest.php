<?php

declare(strict_types=1);

namespace App\Tests\Service\Personality;

use App\Entity\PersonalityProfile;
use App\Enum\PersonalityMould;
use App\Enum\StaffRole;
use App\Service\Personality\PersonalityContext;
use App\Service\Personality\PersonalityGeneratorService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PersonalityGeneratorServiceTest extends TestCase
{
    private const SAMPLES = 4000;

    private PersonalityGeneratorService $gen;

    protected function setUp(): void
    {
        $this->gen = new PersonalityGeneratorService();
    }

    // ── Shape ────────────────────────────────────────────────────────────────

    public function testRollsAllEightTraits(): void
    {
        $traits = $this->gen->rollTraits(PersonalityContext::forPlayer(13));
        $this->assertSame(PersonalityGeneratorService::TRAITS, array_keys($traits));
    }

    public function testEveryTraitStaysInTheOneToTwentyScale(): void
    {
        foreach ($this->contexts() as $label => $ctx) {
            for ($i = 0; $i < 500; $i++) {
                foreach ($this->gen->rollTraits($ctx) as $name => $value) {
                    $this->assertGreaterThanOrEqual(1, $value, "{$label}/{$name}");
                    $this->assertLessThanOrEqual(20, $value, "{$label}/{$name}");
                }
            }
        }
    }

    // ── The actual bug: flat, uniform profiles ───────────────────────────────

    public function testNoGeneratedMatrixIsFlat(): void
    {
        foreach ($this->contexts() as $label => $ctx) {
            for ($i = 0; $i < 500; $i++) {
                $traits = $this->gen->rollTraits($ctx);
                $this->assertGreaterThan(
                    0,
                    max($traits) - min($traits),
                    "{$label} produced a completely uniform matrix",
                );
            }
        }
    }

    public function testGeneratedMatricesShowRealInternalSpread(): void
    {
        $spreads = [];
        for ($i = 0; $i < self::SAMPLES; $i++) {
            $traits    = $this->gen->rollTraits(PersonalityContext::forPlayer(13));
            $spreads[] = max($traits) - min($traits);
        }

        $mean = array_sum($spreads) / count($spreads);
        $this->assertGreaterThan(8.0, $mean, 'Average within-matrix spread is too flat to read as a personality');
    }

    // ── Distribution baseline (the underlying Gaussian, pre-mould) ───────────

    public function testBaseGaussianIsCentredOnTenPointFive(): void
    {
        $draws = [];
        for ($i = 0; $i < 20000; $i++) {
            $draws[] = $this->gen->gaussianInt(10.5, 3.2, 1, 20);
        }

        $mean = array_sum($draws) / count($draws);
        $this->assertEqualsWithDelta(10.5, $mean, 0.2);
    }

    public function testBaseGaussianHasTheSpecifiedSigma(): void
    {
        $draws = [];
        for ($i = 0; $i < 20000; $i++) {
            $draws[] = $this->gen->gaussianInt(10.5, 3.2, 1, 20);
        }

        $mean     = array_sum($draws) / count($draws);
        $variance = array_sum(array_map(fn($d) => ($d - $mean) ** 2, $draws)) / count($draws);

        $this->assertEqualsWithDelta(3.2, sqrt($variance), 0.25);
    }

    public function testBaseGaussianKeepsExtremesRare(): void
    {
        // Spec target: extremes (1–4, 17–20) at ~5–8%. σ=3.2 about a μ of 10.5 yields ≈6%.
        $extremes = 0;
        $n        = 20000;
        for ($i = 0; $i < $n; $i++) {
            $d = $this->gen->gaussianInt(10.5, 3.2, 1, 20);
            if ($d <= 4 || $d >= 17) {
                $extremes++;
            }
        }

        $pct = 100 * $extremes / $n;
        $this->assertGreaterThanOrEqual(4.0, $pct);
        $this->assertLessThanOrEqual(9.0, $pct);
    }

    // ── Age bands ────────────────────────────────────────────────────────────

    public function testYouthProfilesAreMoreVolatileThanEstablishedOnes(): void
    {
        $this->assertGreaterThan(
            $this->spreadOf(PersonalityContext::forPlayer(24)),
            $this->spreadOf(PersonalityContext::forPlayer(13)),
            'Youth intakes must feel rawer than established players',
        );
    }

    public function testYouthPressureAndConsistencySkewLow(): void
    {
        $youth = $this->traitMeans(PersonalityContext::forPlayer(13));
        $adult = $this->traitMeans(PersonalityContext::forPlayer(24));

        $this->assertLessThan($adult['pressure'], $youth['pressure']);
        $this->assertLessThan($adult['consistency'], $youth['consistency']);
    }

    // ── Correlations ─────────────────────────────────────────────────────────
    //
    // Asserted against the rule directly. Sampling finished matrices cannot
    // distinguish a real violation from correct precedence, because a mould is
    // allowed to pin a trait out of a correlation's reach.

    public function testMercenaryDivergenceCapsLoyaltyAgainstHighAmbition(): void
    {
        $out = $this->correlate(['ambition' => 17, 'loyalty' => 15]);
        $this->assertLessThanOrEqual(10, $out['loyalty']);
    }

    public function testModelProCoreLiftsDeterminationAndConsistency(): void
    {
        $out = $this->correlate(['professionalism' => 18, 'determination' => 5, 'consistency' => 4]);
        $this->assertGreaterThanOrEqual(12, $out['determination']);
        $this->assertGreaterThanOrEqual(12, $out['consistency']);
    }

    public function testVolatileGeniusTradeOffCapsPressure(): void
    {
        $out = $this->correlate(['ambition' => 16, 'temperament' => 4, 'pressure' => 15]);
        $this->assertLessThanOrEqual(8, $out['pressure']);
    }

    public function testClubStalwartAnchorBindsAmbitionAndTemperament(): void
    {
        $out = $this->correlate(['loyalty' => 18, 'ambition' => 17, 'temperament' => 3]);
        $this->assertLessThanOrEqual(12, $out['ambition']);
        $this->assertGreaterThanOrEqual(12, $out['temperament']);
    }

    public function testOverlappingRulesBothApplyRegardlessOfOrder(): void
    {
        // Regression: capping Loyalty for high Ambition used to erase the Club
        // Stalwart trigger before it was evaluated, so only one rule ever landed.
        $out = $this->correlate(['ambition' => 17, 'loyalty' => 18, 'temperament' => 3]);

        $this->assertLessThanOrEqual(10, $out['loyalty'], 'Mercenary Divergence did not apply');
        $this->assertLessThanOrEqual(12, $out['ambition'], 'Club Stalwart Anchor did not apply');
        $this->assertGreaterThanOrEqual(12, $out['temperament'], 'Club Stalwart Anchor did not apply');
    }

    public function testCorrelationsLeaveUntriggeredTraitsAlone(): void
    {
        $input = array_fill_keys(PersonalityGeneratorService::TRAITS, 10);
        $this->assertSame($input, $this->gen->applyCorrelations($input, PersonalityMould::BALANCED));
    }

    public function testAMouldPinnedTraitOutranksACorrelation(): void
    {
        // MODEL_PROFESSIONAL holds Ambition moderate, so the Club Stalwart cap
        // must not touch it even though Loyalty is high enough to trigger.
        $out = $this->gen->applyCorrelations(
            array_replace(
                array_fill_keys(PersonalityGeneratorService::TRAITS, 10),
                ['loyalty' => 18, 'ambition' => 13],
            ),
            PersonalityMould::MODEL_PROFESSIONAL,
        );

        $this->assertSame(13, $out['ambition'], 'A mould-pinned trait must survive a correlation');
    }

    // ── Role floors ──────────────────────────────────────────────────────────

    #[DataProvider('leadershipRoles')]
    public function testLeadershipRolesMeetTheirFloors(StaffRole $role): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $t = $this->gen->rollTraits(PersonalityContext::forStaff($role));
            $this->assertGreaterThanOrEqual(11, $t['determination']);
            $this->assertGreaterThanOrEqual(9,  $t['temperament']);
            $this->assertGreaterThanOrEqual(12, $t['pressure']);
        }
    }

    public static function leadershipRoles(): array
    {
        return [
            'manager' => [StaffRole::MANAGER],
            'coach'   => [StaffRole::COACH],
        ];
    }

    public function testRoleFloorsOverrideAConflictingMouldFlaw(): void
    {
        // VOLATILE_PRODIGY flaws Pressure into 1–7, but a manager must clear 12.
        // The eligibility floor is applied last and wins.
        for ($i = 0; $i < 300; $i++) {
            $t = $this->gen->rollTraits(PersonalityContext::forStaff(StaffRole::MANAGER));
            $this->assertGreaterThanOrEqual(12, $t['pressure']);
        }
    }

    public function testNonLeadershipStaffAreNotFloored(): void
    {
        $sawLowPressure = false;
        for ($i = 0; $i < 2000 && !$sawLowPressure; $i++) {
            $t = $this->gen->rollTraits(PersonalityContext::forStaff(StaffRole::FACILITY_MANAGER));
            $sawLowPressure = $t['pressure'] < 12;
        }
        $this->assertTrue($sawLowPressure, 'Facility managers should not carry leadership floors');
    }

    public function testScoutsSkewAdaptableAndConsistent(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $t = $this->gen->rollTraits(PersonalityContext::forScout());
            $this->assertGreaterThanOrEqual(13, $t['adaptability']);
            $this->assertGreaterThanOrEqual(12, $t['consistency']);
        }
    }

    // ── Mould selection ──────────────────────────────────────────────────────

    public function testMouldSelectionTracksTheWeightTable(): void
    {
        $counts = array_fill_keys(array_map(fn($m) => $m->name, PersonalityMould::cases()), 0);
        $n      = 20000;

        for ($i = 0; $i < $n; $i++) {
            $counts[PersonalityMould::pick()->name]++;
        }

        foreach (PersonalityMould::cases() as $mould) {
            $this->assertEqualsWithDelta(
                $mould->weight(),
                100 * $counts[$mould->name] / $n,
                2.0,
                "{$mould->name} selection frequency drifted from its weight",
            );
        }
    }

    public function testWeightsSumToOneHundred(): void
    {
        $this->assertSame(100, array_sum(array_map(fn($m) => $m->weight(), PersonalityMould::cases())));
    }

    // ── Write-through ────────────────────────────────────────────────────────

    public function testApplyWritesEveryTraitOntoTheProfile(): void
    {
        $profile = new PersonalityProfile();
        $this->assertTrue($profile->isDefault());

        $this->gen->apply($profile, PersonalityContext::forPlayer(13));

        $this->assertFalse($profile->isDefault());
        $this->assertSame(PersonalityGeneratorService::TRAITS, array_keys($profile->toArray()));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, PersonalityContext> */
    private function contexts(): array
    {
        return [
            'youth'    => PersonalityContext::forPlayer(13),
            'teen'     => PersonalityContext::forPlayer(18),
            'senior'   => PersonalityContext::forPlayer(28),
            'manager'  => PersonalityContext::forStaff(StaffRole::MANAGER),
            'chairman' => PersonalityContext::forStaff(StaffRole::CHAIRMAN),
            'scout'    => PersonalityContext::forScout(),
        ];
    }

    /**
     * Runs the correlation pass over a baseline matrix overridden with $traits,
     * under a mould that pins nothing.
     *
     * @param  array<string, int> $traits
     * @return array<string, int>
     */
    private function correlate(array $traits): array
    {
        return $this->gen->applyCorrelations(
            array_replace(array_fill_keys(PersonalityGeneratorService::TRAITS, 10), $traits),
            PersonalityMould::BALANCED,
        );
    }

    /** Mean standard deviation within a single generated matrix. */
    private function spreadOf(PersonalityContext $ctx): float
    {
        $total = 0.0;
        for ($i = 0; $i < self::SAMPLES; $i++) {
            $t     = $this->gen->rollTraits($ctx);
            $mean  = array_sum($t) / count($t);
            $total += sqrt(array_sum(array_map(fn($v) => ($v - $mean) ** 2, $t)) / count($t));
        }
        return $total / self::SAMPLES;
    }

    /** @return array<string, float> */
    private function traitMeans(PersonalityContext $ctx): array
    {
        $sums = array_fill_keys(PersonalityGeneratorService::TRAITS, 0);
        for ($i = 0; $i < self::SAMPLES; $i++) {
            foreach ($this->gen->rollTraits($ctx) as $name => $value) {
                $sums[$name] += $value;
            }
        }
        return array_map(fn($s) => $s / self::SAMPLES, $sums);
    }
}
