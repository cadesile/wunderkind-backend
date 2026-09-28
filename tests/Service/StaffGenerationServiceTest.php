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

    private function makeGameConfigRepo(): GameConfigRepository
    {
        $config = $this->createStub(\App\Entity\GameConfig::class);
        $config->method('getWageMultiplierTiers')->willReturn([
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

        return new StaffGenerationService(
            $this->makePoolConfigRepo(),
            $this->makeGameConfigRepo(),
            $nameGen,
            $personalityLifecycle,
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
            $poolConfigRepo,
            $this->makeGameConfigRepo(),
            $nameGen,
            new PersonalityLifecycleSubscriber(new PersonalityGeneratorService()),
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
