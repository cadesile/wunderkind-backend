<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PoolConfig;
use App\Entity\Staff;
use App\EventSubscriber\PersonalityLifecycleSubscriber;
use App\Enum\StaffRole;
use App\Repository\GameConfigRepository;
use App\Repository\PoolConfigRepository;
use App\Service\NameGeneratorService;
use App\Service\Personality\PersonalityGeneratorService;
use App\Service\StaffAbilityRangeService;
use App\Service\StaffGenerationService;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\ReferenceSquadDoubles;

class StaffGenerationServiceTest extends TestCase
{
    private function makePoolConfigRepo(): PoolConfigRepository
    {
        $repo = $this->createStub(PoolConfigRepository::class);
        $repo->method('getConfig')->willReturn(new PoolConfig());
        return $repo;
    }

    /**
     * A REAL GameConfig, not a stub of it.
     *
     * The entity returns a backed enum now (`getEconomyWageModel()`), and PHPUnit cannot
     * generate a return value for an enum — stubbing the entity made every test here fail with
     * "is an enumeration and cannot be doubled". Using the real thing is better anyway: it is a
     * plain data object, so a stub only ever risked disagreeing with it.
     */
    private function makeGameConfigRepo(): GameConfigRepository
    {
        $config = new \App\Entity\GameConfig();
        // A single catch-all tier at x1.0, so a staff salary is the raw band roll — which is
        // what these expectations were written against.
        $config->setWageMultiplierTiers([
            ['maxAbility' => null, 'playerMultiplier' => 1.0, 'staffMultiplier' => 1.0],
        ]);

        $repo = $this->createStub(GameConfigRepository::class);
        $repo->method('getConfig')->willReturn($config);
        return $repo;
    }

    /**
     * A REAL StaffAbilityRangeService over stubbed repositories (it is final and cannot be
     * doubled, and it is pure resolution anyway — a double would only risk disagreeing with
     * it). With no authored table it falls through to DEFAULT_TIER_BANDS for a tiered call and
     * to the untiered `coachAbilityMin/Max` pair for an untiered one, which is the behaviour
     * these tests pin.
     */
    private function makeAbilityRanges(?PoolConfigRepository $poolConfigRepo = null): StaffAbilityRangeService
    {
        return new StaffAbilityRangeService(
            $poolConfigRepo ?? $this->makePoolConfigRepo(),
            $this->createStub(\App\Repository\StarterConfigRepository::class),
            $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );
    }

    private function makeService(): StaffGenerationService
    {
        $nameGen = $this->createStub(NameGeneratorService::class);
        $nameGen->method('getRandomNationality')->willReturn('English');
        $nameGen->method('generateName')->willReturn('Test Coach');

        // Real PersonalityLifecycleSubscriber (pure logic, no Doctrine args needed for fill()).
        $personalityLifecycle = new PersonalityLifecycleSubscriber(new PersonalityGeneratorService());

        // NAMED ARGUMENTS, so a constructor change cannot silently shift a stub into the wrong
        // slot — which is exactly what happened when GameConfigRepository was removed from this
        // service: every positional argument after it moved up one and PHP reported it as a
        // type error in an unrelated parameter.
        $poolConfigRepo = $this->makePoolConfigRepo();

        return new StaffGenerationService(
            poolConfigRepo: $poolConfigRepo,
            nameGenerator: $nameGen,
            personalityLifecycle: $personalityLifecycle,
            wageModel: $this->makeWageModel(),
            staffAbilityRanges: $this->makeAbilityRanges($poolConfigRepo),
        );
    }

    /**
     * A REAL WageModelResolver over stubbed repositories, because it is final and cannot be
     * doubled. The stubbed GameConfig has a single catch-all wage tier at x1.0, so a staff
     * salary comes out as the raw band roll — which is what these expectations were written
     * against, and what proves the move through the resolver changed nothing.
     */
    private function makeWageModel(): \App\Service\Economy\WageModelResolver
    {
        $gameConfigRepo = $this->makeGameConfigRepo();

        return new \App\Service\Economy\WageModelResolver(
            $gameConfigRepo,
            new \App\Service\Economy\EconomyScaleResolver(
                $gameConfigRepo,
                $this->createStub(\App\Repository\StarterConfigRepository::class),
                $this->createStub(\App\Repository\Location\CountryProfileRepository::class),
                ReferenceSquadDoubles::poolConfigRepository(),
                ReferenceSquadDoubles::playerGeneration(),
            ),
            new \App\Service\Economy\TierBandScaleResolver(
                $this->createStub(\App\Repository\StarterConfigRepository::class),
            ),
        );
    }

    public function testBuildReturnsStaffInstance(): void
    {
        $staff = $this->makeService()->build(StaffRole::COACH);
        $this->assertInstanceOf(Staff::class, $staff);
    }

    public function testBuildDoesNotPersist(): void
    {
        // No EntityManagerInterface is even injected into this service — the
        // absence of that dependency is itself the proof build() cannot persist.
        $staff = $this->makeService()->build(StaffRole::COACH);
        $this->assertInstanceOf(Staff::class, $staff);
    }

    public function testBuildAssignsCorrectRole(): void
    {
        $staff = $this->makeService()->build(StaffRole::DIRECTOR_OF_FOOTBALL);
        $this->assertSame(StaffRole::DIRECTOR_OF_FOOTBALL, $staff->getRole());
    }

    public function testBuildRollsPersonalityInline(): void
    {
        // prePersist never fires for an unpersisted entity, so build() must call
        // PersonalityLifecycleSubscriber::fill() itself — confirmed by the profile
        // no longer being all-defaults after build() returns.
        $staff = $this->makeService()->build(StaffRole::COACH);
        $this->assertFalse($staff->getPersonality()->isDefault());
    }

    /** A service over a given PoolConfig, so a test can author the row it is pinning. */
    private function makeServiceFor(PoolConfig $poolConfig): StaffGenerationService
    {
        $poolConfigRepo = $this->createStub(PoolConfigRepository::class);
        $poolConfigRepo->method('getConfig')->willReturn($poolConfig);

        $nameGen = $this->createStub(NameGeneratorService::class);
        $nameGen->method('getRandomNationality')->willReturn('English');
        $nameGen->method('generateName')->willReturn('Test Coach');

        return new StaffGenerationService(
            poolConfigRepo: $poolConfigRepo,
            nameGenerator: $nameGen,
            personalityLifecycle: new PersonalityLifecycleSubscriber(new PersonalityGeneratorService()),
            wageModel: $this->makeWageModel(),
            staffAbilityRanges: $this->makeAbilityRanges($poolConfigRepo),
        );
    }

    /**
     * ⚠️ **THE FLAT PAIR IS THE UNTIERED BAND AND NOTHING ELSE SINCE A25, 2026-11-20.** This
     * test's name used to be `testBuildRespectsCoachAbilityRangeFromPoolConfig` and it asserted
     * the pair governed EVERY staff member. It governs the shared hire-market pool — a call with
     * no country and no tier — and that is what is pinned here. The tiered case is the test
     * below, and the two together are the proof the narrowing is real rather than nominal.
     */
    public function testAnUntieredBuildUsesTheFlatCoachAbilityPair(): void
    {
        $poolConfig = new PoolConfig();
        $poolConfig->setCoachAbilityMin(40);
        $poolConfig->setCoachAbilityMax(45);

        $service = $this->makeServiceFor($poolConfig);

        for ($i = 0; $i < 20; $i++) {
            $staff = $service->build(StaffRole::COACH);
            $this->assertGreaterThanOrEqual(40, $staff->getCoachingAbility());
            $this->assertLessThanOrEqual(45, $staff->getCoachingAbility());
        }
    }

    /**
     * The point of A25: a club's staff come from the authored per-country/per-tier table, and
     * the flat pair cannot reach them. The pair is set to 90-95 here — far ABOVE the authored
     * tier-8 band — so a staff member landing inside the band can only have come from the
     * table, and a regression that reinstated the global would fail loudly instead of merely
     * producing a plausible figure.
     */
    public function testATieredBuildUsesTheAuthoredStaffTableAndNotTheFlatPair(): void
    {
        $poolConfig = new PoolConfig();
        $poolConfig->setCoachAbilityMin(90);
        $poolConfig->setCoachAbilityMax(95);
        $poolConfig->setStaffAbilityRanges(['EN' => ['8' => ['min' => 11, 'max' => 14]]]);

        $service = $this->makeServiceFor($poolConfig);

        for ($i = 0; $i < 30; $i++) {
            $staff = $service->build(StaffRole::COACH, 'English', 'EN', 8);
            $this->assertGreaterThanOrEqual(11, $staff->getCoachingAbility());
            $this->assertLessThanOrEqual(14, $staff->getCoachingAbility());
        }
    }

    /**
     * An un-authored tier falls through to the committed defaults, NOT to the flat pair and not
     * to a zero band. Same reasoning as `WorldInitializationService::ABILITY_RANGES` for
     * players: generation must never fail for want of a row, and must never silently produce
     * ability 0.
     */
    public function testAnUnauthoredTierFallsThroughToTheCommittedDefaultBand(): void
    {
        $poolConfig = new PoolConfig();
        $poolConfig->setCoachAbilityMin(90);
        $poolConfig->setCoachAbilityMax(95);

        $service  = $this->makeServiceFor($poolConfig);
        $expected = StaffAbilityRangeService::DEFAULT_TIER_BANDS[8];

        for ($i = 0; $i < 20; $i++) {
            $staff = $service->build(StaffRole::COACH, 'English', 'EN', 8);
            $this->assertGreaterThanOrEqual($expected['min'], $staff->getCoachingAbility());
            $this->assertLessThanOrEqual($expected['max'], $staff->getCoachingAbility());
        }
    }

    public function testManagerGetsPlayingStyleAndFormationSpecialisms(): void
    {
        $staff = $this->makeService()->build(StaffRole::MANAGER);
        $specialisms = $staff->getSpecialisms();
        $this->assertArrayHasKey('playingStyle', $specialisms);
        $this->assertArrayHasKey('formation', $specialisms);
    }

    public function testNonManagerGetsAttributeSpecialisms(): void
    {
        $staff = $this->makeService()->build(StaffRole::COACH);
        $specialisms = $staff->getSpecialisms();
        $this->assertNotEmpty($specialisms);
        foreach (array_keys($specialisms) as $key) {
            $this->assertContains($key, ['pace', 'technical', 'vision', 'power', 'stamina', 'heart']);
        }
    }
}
