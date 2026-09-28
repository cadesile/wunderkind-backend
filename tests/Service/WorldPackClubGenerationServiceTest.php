<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Agent;
use App\Entity\GameConfig;
use App\Entity\NpcClub;
use App\Entity\PoolConfig;
use App\Entity\Scout;
use App\Entity\Staff;
use App\EventSubscriber\PersonalityLifecycleSubscriber;
use App\Repository\GameConfigRepository;
use App\Repository\PoolConfigRepository;
use App\Service\NameGeneratorService;
use App\Service\Personality\PersonalityGeneratorService;
use App\Service\PlayerGenerationService;
use App\Service\ScoutGenerationService;
use App\Service\StaffGenerationService;
use App\Service\WorldPackClubGenerationService;
use App\Service\WorldPackSnapshotBuilder;
use App\Enum\CitySize;
use PHPUnit\Framework\TestCase;

class WorldPackClubGenerationServiceTest extends TestCase
{
    private function evenPositionWeights(): PoolConfig
    {
        $cfg = new PoolConfig();
        $cfg->setPositionWeightGk(1);
        $cfg->setPositionWeightDef(1);
        $cfg->setPositionWeightMid(1);
        $cfg->setPositionWeightAtt(1);
        return $cfg;
    }

    private function makeService(): WorldPackClubGenerationService
    {
        $nameGen = $this->createStub(NameGeneratorService::class);
        $nameGen->method('getRandomNationality')->willReturn('English');
        $nameGen->method('generateName')->willReturn('Test Person');
        $nameGen->method('generatePlayerName')->willReturn(['firstName' => 'Test', 'lastName' => 'Player']);

        $poolConfigRepo = $this->createStub(PoolConfigRepository::class);
        $poolConfigRepo->method('getConfig')->willReturn(new PoolConfig());

        $gameConfigRepo = $this->createStub(GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn(new GameConfig());

        $personalityLifecycle = new PersonalityLifecycleSubscriber(new PersonalityGeneratorService());

        $playerGen = new PlayerGenerationService($nameGen, $poolConfigRepo, new PersonalityGeneratorService());
        $staffGen  = new StaffGenerationService($poolConfigRepo, $gameConfigRepo, $nameGen, $personalityLifecycle);
        $scoutGen  = new ScoutGenerationService($poolConfigRepo, $nameGen, $personalityLifecycle);

        return new WorldPackClubGenerationService(
            $playerGen,
            $staffGen,
            $scoutGen,
            new WorldPackSnapshotBuilder(),
            $nameGen,
            $gameConfigRepo,
        );
    }

    /** @return array{min: int, max: int} */
    private function abilityRange(): array
    {
        return ['min' => 10, 'max' => 90];
    }

    private function tierConf(array $overrides = []): array
    {
        return array_merge([
            'playerMin'               => 15,
            'playerMax'               => 15,
            'managerCount'            => 1,
            'coachCount'              => 3,
            'chairmanCount'           => 1,
            'directorOfFootballCount' => 1,
            'facilityManagerCount'    => 1,
            'scoutCount'              => 2,
            'foreignPercent'          => 0,
        ], $overrides);
    }

    public function testGeneratePlayersProducesExactlyPlayerMinMaxCount(): void
    {
        $service = $this->makeService();
        $players = $service->generatePlayers($this->tierConf(['playerMin' => 15, 'playerMax' => 15]), $this->abilityRange(), 'English', $this->evenPositionWeights(), []);

        $this->assertCount(15, $players);
    }

    public function testGeneratePlayersRespectsChangedPlayerMinMax(): void
    {
        $service = $this->makeService();

        $small = $service->generatePlayers($this->tierConf(['playerMin' => 8, 'playerMax' => 8]), $this->abilityRange(), 'English', $this->evenPositionWeights(), []);
        $large = $service->generatePlayers($this->tierConf(['playerMin' => 30, 'playerMax' => 30]), $this->abilityRange(), 'English', $this->evenPositionWeights(), []);

        $this->assertCount(8, $small);
        $this->assertCount(30, $large);
    }

    public function testGeneratePlayersAssignsAgentsFromGivenPool(): void
    {
        $agent   = new Agent('Test Agent');
        $service = $this->makeService();

        $players = $service->generatePlayers($this->tierConf(['playerMin' => 5, 'playerMax' => 5]), $this->abilityRange(), 'English', $this->evenPositionWeights(), [$agent]);

        foreach ($players as $player) {
            $this->assertSame($agent, $player->getAgent());
        }
    }

    public function testGeneratePlayersSetsContractValue(): void
    {
        $service = $this->makeService();
        $players = $service->generatePlayers($this->tierConf(['playerMin' => 3, 'playerMax' => 3]), $this->abilityRange(), 'English', $this->evenPositionWeights(), []);

        foreach ($players as $player) {
            $this->assertGreaterThan(0, $player->getContractValue());
        }
    }

    public function testGenerateStaffProducesConfiguredCounts(): void
    {
        $service = $this->makeService();
        $staff   = $service->generateStaff($this->tierConf([
            'managerCount'            => 1,
            'coachCount'              => 5,
            'chairmanCount'           => 1,
            'directorOfFootballCount' => 1,
            'facilityManagerCount'    => 1,
        ]), 'English');

        $this->assertCount(9, $staff);
        $roles = array_map(static fn (Staff $s) => $s->getRole()->value, $staff);
        $this->assertSame(1, count(array_filter($roles, static fn ($r) => $r === 'manager')));
        $this->assertSame(5, count(array_filter($roles, static fn ($r) => $r === 'coach')));
        $this->assertSame(1, count(array_filter($roles, static fn ($r) => $r === 'chairman')));
        $this->assertSame(1, count(array_filter($roles, static fn ($r) => $r === 'director_of_football')));
        $this->assertSame(1, count(array_filter($roles, static fn ($r) => $r === 'facility_manager')));
    }

    public function testGenerateStaffOmitsZeroConfiguredRoles(): void
    {
        $service = $this->makeService();
        $staff   = $service->generateStaff($this->tierConf([
            'directorOfFootballCount' => 0,
            'facilityManagerCount'    => 0,
        ]), 'English');

        $roles = array_map(static fn (Staff $s) => $s->getRole()->value, $staff);
        $this->assertNotContains('director_of_football', $roles);
        $this->assertNotContains('facility_manager', $roles);
    }

    public function testGenerateScoutsProducesConfiguredCount(): void
    {
        $service = $this->makeService();

        $none = $service->generateScouts($this->tierConf(['scoutCount' => 0]), 'English');
        $some = $service->generateScouts($this->tierConf(['scoutCount' => 4]), 'English');

        $this->assertCount(0, $none);
        $this->assertCount(4, $some);
        foreach ($some as $scout) {
            $this->assertInstanceOf(Scout::class, $scout);
        }
    }

    public function testBuildSnapshotDelegatesToSnapshotBuilder(): void
    {
        $service = $this->makeService();
        $club    = new NpcClub('Test FC', 'EN', 8, 50, '#111111', '#eeeeee', 100_000, [], citySize: CitySize::MEDIUM);

        $players = $service->generatePlayers($this->tierConf(['playerMin' => 2, 'playerMax' => 2]), $this->abilityRange(), 'English', $this->evenPositionWeights(), []);
        $staff   = $service->generateStaff($this->tierConf(['managerCount' => 1, 'coachCount' => 0, 'chairmanCount' => 0, 'directorOfFootballCount' => 0, 'facilityManagerCount' => 0]), 'English');
        $scouts  = $service->generateScouts($this->tierConf(['scoutCount' => 0]), 'English');

        $snapshot = $service->buildSnapshot($club, $players, $staff, $scouts);

        $this->assertSame('Test FC', $snapshot['name']);
        $this->assertCount(2, $snapshot['players']);
        $this->assertCount(1, $snapshot['staff']);
        $this->assertCount(0, $snapshot['scouts']);
    }
}
