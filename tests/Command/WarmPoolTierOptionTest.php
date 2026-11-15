<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\WarmPoolCommand;
use App\Repository\PlayerRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Service\MarketPoolService;
use App\Repository\Location\CountryProfileRepository;
use App\Service\CountryContent\NamePoolProvider;
use App\Service\StarterPackPoolWarmService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:pool:warm --tier` is what the GENERATE STARTER PACK button presses. Without it the
 * command generates from PoolConfig's flat distribution, which aims at no tier and so can
 * never fill a tier band that is empty.
 */
final class WarmPoolTierOptionTest extends TestCase
{
    /** @var list<array{country: string, tier: int, squads: int}> */
    private array $tierWarms = [];

    public function testTierRunsTheBandTargetedPassForThatCountryAndTier(): void
    {
        $tester = $this->tester();
        $tester->execute(['country' => 'EN', '--tier' => '8', '--min-players' => 0, '--min-staff' => 0, '--min-scouts' => 0]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([['country' => 'EN', 'tier' => 8, 'squads' => StarterPackPoolWarmService::DEFAULT_SQUADS]], $this->tierWarms);
        $this->assertStringContainsString('tier 8', $tester->getDisplay());
    }

    /** Omitting --tier must leave the old behaviour byte for byte: no tier pass at all. */
    public function testWithoutTierNothingBandTargetedHappens(): void
    {
        $tester = $this->tester();
        $tester->execute(['country' => 'EN', '--min-players' => 0, '--min-staff' => 0, '--min-scouts' => 0]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([], $this->tierWarms);
    }

    public function testSquadsIsPassedThrough(): void
    {
        $tester = $this->tester();
        $tester->execute(['country' => 'EN', '--tier' => '8', '--squads' => '5', '--min-players' => 0, '--min-staff' => 0, '--min-scouts' => 0]);

        $this->assertSame(5, $this->tierWarms[0]['squads']);
    }

    /** A band belongs to a (country, tier) pair, so there is nothing to resolve without a country. */
    public function testTierWithoutACountryIsRefused(): void
    {
        $tester = $this->tester();
        $tester->execute(['--tier' => '8']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('needs a country', $tester->getDisplay());
        $this->assertSame([], $this->tierWarms);
    }

    public function testATierOutsideThePyramidIsRefused(): void
    {
        $tester = $this->tester();
        $tester->execute(['country' => 'EN', '--tier' => '9']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('between 1 and 8', $tester->getDisplay());
        $this->assertSame([], $this->tierWarms);
    }

    private function tester(): CommandTester
    {
        $this->tierWarms = [];

        $warm = $this->createStub(StarterPackPoolWarmService::class);
        $warm->method('warmForTier')->willReturnCallback(
            function (string $country, int $tier, int $squads = StarterPackPoolWarmService::DEFAULT_SQUADS): array {
                $this->tierWarms[] = ['country' => $country, 'tier' => $tier, 'squads' => $squads];

                return [
                    'country' => $country, 'tier' => $tier, 'nationality' => 'English', 'squads' => $squads,
                    'band' => ['min' => 2, 'max' => 7, 'source' => 'starterConfig.leagueAbilityRanges'],
                    'ageRange' => ['min' => 19, 'max' => 33],
                    'positions' => [[
                        'position' => 'GK', 'requested' => 2, 'available' => 0, 'target' => 6,
                        'deficit' => 6, 'generated' => 6, 'attempts' => 9, 'capped' => false,
                    ]],
                    'generated' => 6, 'attempts' => 9, 'shortOfTarget' => false,
                ];
            }
        );

        $playerRepo = $this->createStub(PlayerRepository::class);
        $playerRepo->method('countInPoolByNationality')->willReturn(1_000);

        $staffRepo = $this->createStub(StaffRepository::class);
        $staffRepo->method('countInPoolByNationalityAndRole')->willReturn(1_000);

        $scoutRepo = $this->createStub(ScoutRepository::class);
        $scoutRepo->method('countByNationality')->willReturn(1_000);

        // NamePoolProvider is final; the real one over a stubbed repository is enough,
        // since all this command asks it is whether a nationality has a name pool.
        $profiles = $this->createStub(CountryProfileRepository::class);
        $profiles->method('findAll')->willReturn([]);
        $namePools = new NamePoolProvider($profiles);

        $command = new WarmPoolCommand(
            $this->createStub(MarketPoolService::class),
            $playerRepo,
            $staffRepo,
            $scoutRepo,
            $namePools,
            $warm,
        );

        return new CommandTester($command);
    }
}
