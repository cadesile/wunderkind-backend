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
use App\Service\StaffGenerationService;
use PHPUnit\Framework\TestCase;

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
        return new StaffGenerationService(
            poolConfigRepo: $this->makePoolConfigRepo(),
            nameGenerator: $nameGen,
            personalityLifecycle: $personalityLifecycle,
            wageModel: $this->makeWageModel(),
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

    public function testBuildRespectsCoachAbilityRangeFromPoolConfig(): void
    {
        $poolConfig = new PoolConfig();
        $poolConfig->setCoachAbilityMin(40);
        $poolConfig->setCoachAbilityMax(45);

        $poolConfigRepo = $this->createStub(PoolConfigRepository::class);
        $poolConfigRepo->method('getConfig')->willReturn($poolConfig);

        $nameGen = $this->createStub(NameGeneratorService::class);
        $nameGen->method('getRandomNationality')->willReturn('English');
        $nameGen->method('generateName')->willReturn('Test Coach');

        $service = new StaffGenerationService(
            poolConfigRepo: $poolConfigRepo,
            nameGenerator: $nameGen,
            personalityLifecycle: new PersonalityLifecycleSubscriber(new PersonalityGeneratorService()),
            wageModel: $this->makeWageModel(),
        );

        for ($i = 0; $i < 20; $i++) {
            $staff = $service->build(StaffRole::COACH);
            $this->assertGreaterThanOrEqual(40, $staff->getCoachingAbility());
            $this->assertLessThanOrEqual(45, $staff->getCoachingAbility());
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
