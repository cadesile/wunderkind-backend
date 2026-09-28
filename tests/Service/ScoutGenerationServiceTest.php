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

        return new ScoutGenerationService(
            $this->makePoolConfigRepo($config),
            $nameGen,
            new PersonalityLifecycleSubscriber(new PersonalityGeneratorService()),
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

        $service = new ScoutGenerationService(
            $this->makePoolConfigRepo(),
            $nameGen,
            new PersonalityLifecycleSubscriber(new PersonalityGeneratorService()),
        );

        $scout = $service->build('Brazilian');
        $this->assertSame('Brazilian', $scout->getNationality());
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
