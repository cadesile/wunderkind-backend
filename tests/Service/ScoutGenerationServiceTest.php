<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PoolConfig;
use App\Entity\Scout;
use App\EventSubscriber\PersonalityLifecycleSubscriber;
use App\Repository\PoolConfigRepository;
use App\Service\NameGeneratorService;
use App\Service\Personality\PersonalityGeneratorService;
use App\Service\ScoutGenerationService;
use App\Service\ScoutWageCalculator;
use App\Service\ScoutAbilityCalculator;
use PHPUnit\Framework\TestCase;

class ScoutGenerationServiceTest extends TestCase
{
    private function makePoolConfigRepo(?PoolConfig $config = null): PoolConfigRepository
    {
        $repo = $this->createStub(PoolConfigRepository::class);
        $repo->method('getConfig')->willReturn($config ?? new PoolConfig());
        return $repo;
    }

    private function makeService(?PoolConfig $config = null): ScoutGenerationService
    {
        $nameGen = $this->createStub(NameGeneratorService::class);
        $nameGen->method('getRandomNationality')->willReturn('English');
        $nameGen->method('generateName')->willReturn('Test Scout');

        $poolRepo = $this->makePoolConfigRepo($config);

        return new ScoutGenerationService(
            $poolRepo,
            $nameGen,
            new PersonalityLifecycleSubscriber(new PersonalityGeneratorService()),
            new ScoutWageCalculator($poolRepo),
            new ScoutAbilityCalculator(),
        );
    }

    public function testBuildReturnsScoutInstance(): void
    {
        $scout = $this->makeService()->build();
        $this->assertInstanceOf(Scout::class, $scout);
    }

    public function testBuildRollsPersonalityInline(): void
    {
        $scout = $this->makeService()->build();
        $this->assertFalse($scout->getPersonality()->isDefault());
    }

    public function testBuildUsesGivenNationalityOverRandom(): void
    {
        $nameGen = $this->createMock(NameGeneratorService::class);
        $nameGen->expects($this->never())->method('getRandomNationality');
        $nameGen->method('generateName')->willReturn('Test Scout');

        $poolRepo = $this->makePoolConfigRepo();
        $service  = new ScoutGenerationService(
            $poolRepo,
            $nameGen,
            new PersonalityLifecycleSubscriber(new PersonalityGeneratorService()),
            new ScoutWageCalculator($poolRepo),
            new ScoutAbilityCalculator(),
        );

        $scout = $service->build('Brazilian');
        $this->assertSame('Brazilian', $scout->getNationality());
    }

    /**
     * A club's tier band wins over the global pool range.
     *
     * The defect this pins: scout experience came from one global range (4-34 on dev) whatever
     * the club, so a tier-8 side got a scout rated 33 beside coaches rated 2-7 — and the app,
     * which splits a club's wage reservation in proportion to ability, paid that one scout
     * £2,230/wk of a £4,308 staff bill. The pool range here is set deliberately DISJOINT from
     * the band, so a draw from the wrong source cannot pass by luck.
     */
    public function testATierBandOverridesTheGlobalExperienceRange(): void
    {
        $config = new PoolConfig();
        $config->setScoutExperienceMin(60);
        $config->setScoutExperienceMax(90);

        $service = $this->makeService($config);

        for ($i = 0; $i < 50; $i++) {
            $scout = $service->build('English', ['min' => 2, 'max' => 7]);
            $this->assertGreaterThanOrEqual(2, $scout->getExperience());
            $this->assertLessThanOrEqual(7, $scout->getExperience());
        }
    }

    /**
     * The wage follows the DERIVED ABILITY — `experience` until A24, 2026-11-20 (owner ruling).
     *
     * 🔴 **THE ORDERING GUARD, AND IT IS WHY THIS TEST IS WORTH ITS LINES.** `setWeeklySalary()`
     * used to sit immediately after `setExperience()`, because it interpolated on experience.
     * Pricing on `ability` means it must now run AFTER the judgements are rolled — and a wage
     * priced before that would silently use the entity's floor of 1 and put every generated scout
     * at the very bottom of the band. `assertNotSame` against the floor is what catches it; the
     * banding half is still asserted, one step removed, because ability is 70% banded experience.
     */
    public function testTheWageIsDerivedFromTheDerivedAbility(): void
    {
        $config = new PoolConfig();
        $service = $this->makeService($config);
        $calculator = new ScoutWageCalculator($this->makePoolConfigRepo($config));

        $scout = $service->build('English', ['min' => 5, 'max' => 5]);

        $this->assertSame(5, $scout->getExperience());
        $this->assertSame(
            $calculator->weeklyWageFor($scout->getAbility(), $config),
            $scout->getWeeklySalary(),
        );
        $this->assertNotSame(
            $calculator->weeklyWageFor(1, $config),
            $scout->getWeeklySalary(),
            'a wage priced before the ability was derived would land on the column floor of 1',
        );
        $this->assertNotSame(
            $calculator->weeklyWageFor(5, $config),
            $scout->getWeeklySalary(),
            'and it must not be priced on experience either — that is the A24 ruling',
        );
    }

    /** Banding the experience still moves the wage, one step removed through `ability`. */
    public function testTheWageStillFollowsTheBandedExperienceThroughAbility(): void
    {
        $config = new PoolConfig();
        $service = $this->makeService($config);

        $low  = $service->build('English', ['min' => 5, 'max' => 5])->getWeeklySalary();
        $high = $service->build('English', ['min' => 60, 'max' => 60])->getWeeklySalary();

        $this->assertGreaterThan($low, $high, 'a tier-1 club scout must cost more than a tier-8 one');
    }

    /**
     * `ability` must be WIRED, not merely present — the calculator has its own tests, and this
     * asserts generation actually calls it rather than leaving the column's floor of 1.
     */
    public function testAbilityIsDerivedFromTheRolledExperienceAndJudgements(): void
    {
        $config  = new PoolConfig();
        $service = $this->makeService($config);

        $scout = $service->build('English', ['min' => 60, 'max' => 60]);

        $this->assertSame(
            (new ScoutAbilityCalculator())->abilityFor(60, $scout->getJudgements()),
            $scout->getAbility(),
            'generation must store exactly what the calculator derives for this scout',
        );
    }

    /**
     * ⚠️ A26's load-bearing invariant: a zero ability would price a scout at the very bottom of
     * the wage band A24 will build, and the column's unbackfilled floor of 1 is indistinguishable
     * from a derived 1. With the default `scoutJudgementMin` of 40 and any experience, the blend
     * cannot land that low, so a 1 here means the derivation did not run.
     */
    public function testAGeneratedScoutIsNeverLeftAtTheAbilityFloor(): void
    {
        $service = $this->makeService();

        for ($i = 0; $i < 25; $i++) {
            $ability = $service->build('English', ['min' => 20, 'max' => 34])->getAbility();

            $this->assertGreaterThan(1, $ability, 'a generated scout must carry a derived ability');
            $this->assertLessThanOrEqual(100, $ability);
        }
    }

    /**
     * The seven keys this service writes ARE the authoritative set — see its own comment. Pinned
     * because `GenerateMarketDataCommand` writes its own seven for a different population, and
     * anything averaging judgements over a fixed divisor would silently misrate every real scout.
     *
     * ⚠️ **THIS PIN HELD FIVE KEYS UNTIL 2026-11-20 AND WAS CHANGED ON AN OWNER RULING, NOT
     * BECAUSE IT WAS IN THE WAY.** A26 pinned the five-key set deliberately, so that the 5-vs-7
     * disagreement between the two repos could not be resolved silently in either direction. It
     * then did its job: A27 could not add `youth` and `senior` without coming through here.
     *
     * The ruling it is changed on, 2026-11-20, asked whether scouting accuracy should vary by the
     * player's age: **"Yes, it should."** The two keys are what makes that true. The app's
     * `ageGateFor()` reads `judgements.youth` under 18 and `judgements.senior` at 18 and above,
     * and this service had never written either — so the gate returned 0 for all 5,278 scouts on
     * dev and every scout in every save, making each one's displayed accuracy 70% of its designed
     * value and leaving the noise band permanently untightened.
     *
     * ⚠️ **WHAT THIS PIN IS STILL GUARDING, now that the set has grown: adding or removing a key
     * here changes `ScoutAbilityCalculator`'s judgement mean, and therefore every scout's
     * `ability` and (from A24) every scout's WAGE**, because the mean is taken over keys present.
     * So a change to this set needs `app:backfill-scout-abilities --force` behind it and a
     * `WORLD_PACK_VERSION` bump. If this assertion is failing for you, that is the pin working —
     * establish the ruling and the backfill before you edit it, as A27 did.
     */
    public function testJudgementsAreTheSevenAuthoritativeKeys(): void
    {
        $judgements = $this->makeService()->build('English')->getJudgements();

        $this->assertSame(
            ['potential', 'technical', 'physical', 'mental', 'personality', 'youth', 'senior'],
            array_keys($judgements),
        );
    }

    /**
     * The age-band keys must be REAL, INDEPENDENT rolls within the configured judgement bounds —
     * not a copy of another key, and not a constant.
     *
     * Pinned because the cheap alternative was specifically considered and rejected: deriving
     * `youth` from `potential` collapses the app's displayed-accuracy blend
     * `potential x 0.7 + ageGate x 0.3` to `potential x 0.7 + potential x 0.3` = `potential`, so
     * the gate would do nothing for exactly the under-18s it exists for. A scout must be able to
     * be better at judging youth than seniors, which is what makes accuracy vary with age at all.
     */
    public function testTheAgeBandJudgementsAreIndependentRollsWithinTheConfiguredBounds(): void
    {
        $config = new PoolConfig();
        $config->setScoutJudgementMin(20);
        $config->setScoutJudgementMax(70);

        $service  = $this->makeService($config);
        $differed = false;

        for ($i = 0; $i < 60; $i++) {
            $judgements = $service->build('English')->getJudgements();

            foreach (['youth', 'senior'] as $key) {
                $this->assertArrayHasKey($key, $judgements);
                $this->assertGreaterThanOrEqual(20, $judgements[$key]);
                $this->assertLessThanOrEqual(70, $judgements[$key]);
            }

            // Not asserted per-iteration: two independent rolls CAN coincide. Over 60 builds
            // across a 51-wide band, never differing would mean they are not independent.
            if ($judgements['youth'] !== $judgements['senior']
                || $judgements['youth'] !== $judgements['potential']) {
                $differed = true;
            }
        }

        $this->assertTrue(
            $differed,
            'youth/senior must be rolled independently, not derived from another judgement',
        );
    }

    /** A transposed band is a real state of a hand-edited admin grid, and must not throw. */
    public function testATransposedBandIsNormalised(): void
    {
        $scout = $this->makeService()->build('English', ['min' => 7, 'max' => 2]);

        $this->assertGreaterThanOrEqual(2, $scout->getExperience());
        $this->assertLessThanOrEqual(7, $scout->getExperience());
    }

    public function testBuildRespectsExperienceRangeFromPoolConfig(): void
    {
        $config = new PoolConfig();
        $config->setScoutExperienceMin(10);
        $config->setScoutExperienceMax(12);

        $service = $this->makeService($config);

        for ($i = 0; $i < 20; $i++) {
            $scout = $service->build();
            $this->assertGreaterThanOrEqual(10, $scout->getExperience());
            $this->assertLessThanOrEqual(12, $scout->getExperience());
        }
    }

    public function testBuildRespectsJudgementRangeFromPoolConfig(): void
    {
        $config = new PoolConfig();
        $config->setScoutJudgementMin(60);
        $config->setScoutJudgementMax(65);

        $service = $this->makeService($config);
        $scout   = $service->build();

        foreach ($scout->getJudgements() as $value) {
            $this->assertGreaterThanOrEqual(60, $value);
            $this->assertLessThanOrEqual(65, $value);
        }
    }
}
