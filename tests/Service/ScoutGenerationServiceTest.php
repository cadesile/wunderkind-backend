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

    /** The wage follows the banded experience, since it is interpolated on the same number. */
    public function testTheWageIsDerivedFromTheBandedExperience(): void
    {
        $config = new PoolConfig();
        $service = $this->makeService($config);
        $calculator = new ScoutWageCalculator($this->makePoolConfigRepo($config));

        $scout = $service->build('English', ['min' => 5, 'max' => 5]);

        $this->assertSame(5, $scout->getExperience());
        $this->assertSame($calculator->weeklyWageFor(5, $config), $scout->getWeeklySalary());
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
     * The five keys this service writes ARE the authoritative set — see its own comment. Pinned
     * because `GenerateMarketDataCommand` writes seven for a different population, and anything
     * averaging judgements over a fixed divisor would silently under-rate every real scout.
     */
    public function testJudgementsAreTheFiveAuthoritativeKeys(): void
    {
        $judgements = $this->makeService()->build('English')->getJudgements();

        $this->assertSame(
            ['potential', 'technical', 'physical', 'mental', 'personality'],
            array_keys($judgements),
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
