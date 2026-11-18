<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\GameConfig;
use App\Entity\StarterConfig;
use App\Repository\GameConfigRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\StarterConfigRepository;
use App\Service\Economy\EconomyScaleResolver;
use PHPUnit\Framework\TestCase;

/**
 * The authored turnover table (resolution R2), and the wage scale DERIVED from it.
 *
 * ── WHY THIS TEST EXISTS ──────────────────────────────────────────────────────────────────
 *
 * `Version20261118120000` authors ONE table — `economyBaselineTurnoverPence` — and two separate
 * things fall out of it with no second authoring action:
 *
 *  - the figure published to the app as `economyTierBaseline.tiers[].turnoverPence`, and
 *  - `wageScaleMicroPence`, which `EconomyScaleResolver` computes as
 *    `playerBudget / (weeksPerSeason x sum(ability^k))`.
 *
 * Because the second is derived, NOTHING ELSE IN THE REPOSITORY WOULD NOTICE if the authored
 * row were tidied, re-rounded or re-ordered — the chain would simply publish a different wage
 * scale and stay internally consistent while disagreeing with every figure in the brief, the
 * migration docblock and `REMAINING-WORK.md`. That is the failure this pins.
 *
 * ── THE CONSTRAINT THAT DECIDES THE LADDER'S SHAPE ────────────────────────────────────────
 *
 * `app:economy:wage-table` check 1 requires `S_tier` to fall STRICTLY down the pyramid: if a
 * lower division paid more per unit of ability, a promoted player would take a pay cut. R2's
 * turnover values are therefore not free to round — each is the LARGEST figure that keeps the
 * chain decreasing given the tier above it, already rounded DOWN to three significant figures so
 * the chain survives rounding.
 *
 * ⚠️ AND IT IS NOT A PROPERTY OF THE TURNOVER ROW ALONE. `S_tier` divides turnover by
 * `sum(ability^k)` over a REFERENCE SQUAD, so the ability bands AND the squad sizes decide it
 * jointly — which is why this test authors all three explicitly rather than leaning on whatever
 * a given box happens to hold. The ladder was derived against the squad row below; a box holding
 * a different one derives a different scale from the same authored turnover, and tier 7 -> 8 is
 * where that shows up first.
 */
class EconomyBaselineTurnoverAuthoringTest extends TestCase
{
    /** R2, in pence, exactly as `Version20261118120000` authors it. */
    private const TURNOVER = [
        33100000000, 11400000000, 3460000000, 2070000000,
        732000000, 269000000, 68600000, 21500000,
    ];

    /** What the resolver must publish for EN at parity, in micro-pence. */
    private const EXPECTED_SCALE = [
        332484503, 331618346, 303799192, 290519761,
        258711870, 257021938, 235402484, 233303081,
    ];

    /** England's authored ability bands — an INPUT, and `starter_config` territory. Not ours. */
    private const EN_BANDS = [
        1 => ['min' => 40, 'max' => 90],
        2 => ['min' => 30, 'max' => 60],
        3 => ['min' => 20, 'max' => 40],
        4 => ['min' => 15, 'max' => 35],
        5 => ['min' => 10, 'max' => 25],
        6 => ['min' => 10, 'max' => 15],
        7 => ['min' => 5,  'max' => 10],
        8 => ['min' => 2,  'max' => 7],
    ];

    /**
     * The reference squad sizes the R2 ladder was derived against — midpoints 26, 23, 21, 20,
     * 19, 18, 17, 17.
     *
     * `referenceSquadSize()` takes the midpoint of each row's `playerMin`/`playerMax`, and these
     * are the sizes that reproduce the live published scale from the live authored turnover.
     */
    private const SQUAD = [
        1 => ['playerMin' => 25, 'playerMax' => 27],
        2 => ['playerMin' => 22, 'playerMax' => 24],
        3 => ['playerMin' => 20, 'playerMax' => 22],
        4 => ['playerMin' => 19, 'playerMax' => 21],
        5 => ['playerMin' => 18, 'playerMax' => 20],
        6 => ['playerMin' => 17, 'playerMax' => 19],
        7 => ['playerMin' => 16, 'playerMax' => 18],
        8 => ['playerMin' => 16, 'playerMax' => 18],
    ];

    // ── The authored row itself ──────────────────────────────────────────

    public function testTheAuthoredTurnoverRowIsExactlyTheR2Table(): void
    {
        // THE PUBLISHED FIGURE, per tier. This is what reaches the app as
        // `economyTierBaseline.tiers[].turnoverPence`, and what every COVERAGE number in the
        // brief is measured against.
        $resolver = $this->resolver();

        foreach (self::TURNOVER as $i => $pence) {
            $scale = $resolver->resolve('EN', $i + 1);

            self::assertSame($pence, $scale->baselineTurnoverPence, 'tier ' . ($i + 1) . ' baseline');
            self::assertSame($pence, $scale->turnoverPence, 'tier ' . ($i + 1) . ' at parity');
            self::assertTrue($scale->isResolvable, 'tier ' . ($i + 1) . ' must be resolvable');
        }
    }

    public function testTheAuthoredTurnoverFallsStrictlyDownThePyramid(): void
    {
        for ($i = 1; $i < 8; $i++) {
            self::assertLessThan(
                self::TURNOVER[$i - 1],
                self::TURNOVER[$i],
                'turnover rises at tier ' . ($i + 1),
            );
        }
    }

    public function testEveryAuthoredValueIsThreeSignificantFigures(): void
    {
        // NOT COSMETIC. The brief forbids re-rounding these, and 3 s.f. rounded DOWN is what
        // leaves the chain enough headroom to stay strictly decreasing. A value that is not
        // 3 s.f. is a value somebody tidied.
        foreach (self::TURNOVER as $i => $pence) {
            $digits = strlen((string) $pence);
            $kept   = (int) substr((string) $pence, 0, 3);

            self::assertSame(
                $kept * 10 ** ($digits - 3),
                $pence,
                sprintf('tier %d (%d) is not three significant figures', $i + 1, $pence),
            );
        }
    }

    // ── The scale derived from it ────────────────────────────────────────

    public function testTheDerivedWageScaleIsStrictlyDecreasing(): void
    {
        // CHECK 1 OF `app:economy:wage-table`, as a unit test. The whole reason R2's ladder is
        // shaped the way it is: a lower division must never pay more per unit of ability.
        $resolver = $this->resolver();
        $previous = null;

        for ($tier = 1; $tier <= 8; $tier++) {
            $scale = $resolver->resolve('EN', $tier)->wageScaleMicroPence;

            if ($previous !== null) {
                self::assertLessThan(
                    $previous,
                    $scale,
                    sprintf('S_tier does not fall at tier %d (%d is not below %d)', $tier, $scale, $previous),
                );
            }

            $previous = $scale;
        }
    }

    public function testTheDerivedWageScaleIsExactlyTheFiguresTheBriefAsserts(): void
    {
        // Pinned as integers, because "strictly decreasing" alone would also be satisfied by a
        // ladder derived from the wrong turnover row. These are the figures the R2 brief, the
        // migration docblock and the admin preview all have to agree on.
        $resolver = $this->resolver();

        foreach (self::EXPECTED_SCALE as $i => $micro) {
            self::assertSame(
                $micro,
                $resolver->resolve('EN', $i + 1)->wageScaleMicroPence,
                'tier ' . ($i + 1) . ' wage scale',
            );
        }
    }

    public function testNothingIsAuthoredForWagesOnTheConfigItself(): void
    {
        // STEP 2 OF THE BRIEF IS DELIBERATELY EMPTY, and this is what "empty" has to mean: the
        // scale is a property of the resolver, not a column somebody filled in beside the
        // turnover row. If a `wageScaleMicroPence` setter ever appears on GameConfig, the chain
        // gains a second source of truth and this test is the one that should fail first.
        self::assertFalse(
            method_exists(GameConfig::class, 'setWageScaleMicroPence'),
            'wageScaleMicroPence must stay derived, never authored',
        );
    }

    public function testAnUnauthoredTurnoverRowStillPublishesNoScaleAtAll(): void
    {
        // THE SHIPPED STATE, kept reachable: the turnover wage model must stay inert until an
        // operator fills the table in, rather than degrading to some fraction of a wage.
        $resolver = $this->resolver(array_fill(0, 8, 0));

        $scale = $resolver->resolve('EN', 1);

        self::assertFalse($scale->isResolvable);
        self::assertSame(0, $scale->wageScaleMicroPence);
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /** @param list<int>|null $turnover */
    private function resolver(?array $turnover = null): EconomyScaleResolver
    {
        $config = new GameConfig();
        $config->setEconomyBaselineTurnoverPence($turnover ?? self::TURNOVER);

        $starter = new StarterConfig();
        $starter->setLeagueAbilityRanges(['EN' => self::EN_BANDS]);
        $starter->setNpcSquadConfig(self::SQUAD);

        $gameConfigRepo = $this->createStub(GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn($config);

        $starterRepo = $this->createStub(StarterConfigRepository::class);
        $starterRepo->method('getConfig')->willReturn($starter);

        $profileRepo = $this->createStub(CountryProfileRepository::class);
        $profileRepo->method('findByCode')->willReturn(null);
        $profileRepo->method('findEconomyBaseline')->willReturn(null);

        return new EconomyScaleResolver($gameConfigRepo, $starterRepo, $profileRepo);
    }
}
