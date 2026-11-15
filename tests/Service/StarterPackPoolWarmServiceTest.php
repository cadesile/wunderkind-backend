<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Player;
use App\Entity\PoolConfig;
use App\Entity\StarterConfig;
use App\Enum\PlayerPosition;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Repository\StarterConfigRepository;
use App\Service\MarketPoolService;
use App\Service\StarterPackDiagnosticService;
use App\Service\StarterPackPoolWarmService;
use App\Service\StarterPackService;
use App\Service\WorldPackSnapshotBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What GENERATE STARTER PACK now does: read the shortfall off the check, then generate
 * against the tier's band through the world pack's generation call.
 */
final class StarterPackPoolWarmServiceTest extends TestCase
{
    /** @var list<array{count: int, position: string, nationality: ?string, band: array, ages: array}> */
    private array $generated = [];

    /**
     * The deficit is read from StarterPackDiagnosticService, not re-derived, so GENERATE
     * fills exactly what RUN CHECK reports short — per position, and with squads of
     * headroom, because the draw CONSUMES the rows it takes.
     */
    public function testItGeneratesTheShortfallThatTheCheckReports(): void
    {
        $service = $this->service(positions: [
            ['position' => 'GK',  'requested' => 2, 'available' => 0],
            ['position' => 'DEF', 'requested' => 7, 'available' => 5],
            ['position' => 'MID', 'requested' => 6, 'available' => 99],
        ]);

        $result = $service->warmForTier('EN', 8, squads: 2);

        // GK: target 4, had 0 → 4. DEF: target 14, had 5 → 9. MID: target 12, had 99 → none.
        $this->assertSame(['GK' => 4, 'DEF' => 9], array_column($this->generated, 'count', 'position'));
        $this->assertSame(13, $result['generated']);

        $byPosition = array_column($result['positions'], null, 'position');
        $this->assertSame(0, $byPosition['MID']['deficit'], 'a position with headroom must not be topped up');
        $this->assertSame(4, $byPosition['GK']['target']);
        $this->assertFalse($result['shortOfTarget']);
    }

    /**
     * The band comes from StarterPackService::resolveAbilityRange() — the same resolver the
     * real draw and the check use. A second derivation here would be free to drift from the
     * page it is meant to satisfy, and would generate players into a band nobody draws from.
     */
    public function testItGeneratesIntoTheSameBandTheDrawWillAskFor(): void
    {
        $starter = new StarterConfig();
        $starter->setLeagueAbilityRanges(['EN' => ['8' => ['min' => 11, 'max' => 19]]]);

        $service = $this->service(
            positions: [['position' => 'GK', 'requested' => 1, 'available' => 0]],
            starterConfig: $starter,
        );

        $result = $service->warmForTier('EN', 8, squads: 1);

        $this->assertSame(['min' => 11, 'max' => 19], $this->generated[0]['band']);
        $this->assertSame(11, $result['band']['min']);
        $this->assertSame(19, $result['band']['max']);
        $this->assertSame('starterConfig.leagueAbilityRanges', $result['band']['source']);
        $this->assertSame('English', $this->generated[0]['nationality'], 'the draw asks for natives first');
    }

    /**
     * The age range is PoolConfig::firstTeamAgeRange(), exactly as
     * WorldPackClubGenerationService passes it. Without it the pool's youth span applies and
     * the draw's `date_of_birth <= today - firstTeamAgeMin` filter throws the result away —
     * the band would fill with children and the check would stay red.
     */
    public function testItGeneratesInsideTheFirstTeamAgeRange(): void
    {
        $pool = new PoolConfig();
        $pool->setPlayerFirstTeamAgeMin(19);
        $pool->setPlayerAgeMax(34);

        $service = $this->service(
            positions: [['position' => 'GK', 'requested' => 1, 'available' => 0]],
            poolConfig: $pool,
        );

        $result = $service->warmForTier('EN', 8);

        $this->assertSame(['min' => 19, 'max' => 34], $this->generated[0]['ages']);
        $this->assertSame(['min' => 19, 'max' => 34], $result['ageRange']);
    }

    /** A band the generator cannot hit is reported, never papered over. */
    public function testAShortGenerationIsReported(): void
    {
        $service = $this->service(
            positions: [['position' => 'GK', 'requested' => 4, 'available' => 0]],
            deliver: 1,
        );

        $result = $service->warmForTier('EN', 8, squads: 1);

        $this->assertTrue($result['shortOfTarget']);
        $this->assertSame(1, $result['generated']);
        $this->assertSame(4, $result['positions'][0]['deficit']);
    }

    /** One press cannot be talked into generating thousands of rows. */
    public function testPerPositionGenerationIsCapped(): void
    {
        $service = $this->service(positions: [['position' => 'GK', 'requested' => 1000, 'available' => 0]]);

        $result = $service->warmForTier('EN', 8, squads: 5);

        $this->assertSame(StarterPackPoolWarmService::MAX_PER_POSITION, $this->generated[0]['count']);
        $this->assertTrue($result['positions'][0]['capped']);
    }

    /**
     * @param list<array{position: string, requested: int, available: int}> $positions
     * @param int|null $deliver how many players generation returns, null = everything asked for
     */
    private function service(
        array $positions,
        ?StarterConfig $starterConfig = null,
        ?PoolConfig $poolConfig = null,
        ?int $deliver = null,
    ): StarterPackPoolWarmService {
        $this->generated = [];

        $starterConfig = $starterConfig ?? new StarterConfig();
        $poolConfig    = $poolConfig ?? new PoolConfig();

        $starterRepo = $this->createStub(StarterConfigRepository::class);
        $starterRepo->method('getConfig')->willReturn($starterConfig);

        $poolRepo = $this->createStub(PoolConfigRepository::class);
        $poolRepo->method('getConfig')->willReturn($poolConfig);

        $starterPack = $this->createStub(StarterPackService::class);
        $starterPack->method('resolveAbilityRange')->willReturnCallback(
            fn (string $country, int $tier): array => $this->bandFrom($starterConfig, $country, $tier)
        );

        // StarterPackDiagnosticService is final, so this is the real one over stubbed
        // repositories — which is the point: the deficit this service acts on is the number
        // the admin page prints, arrived at by the page's own code path.
        $requested = array_column($positions, 'requested', 'position');
        $available = array_column($positions, 'available', 'position');

        $snapshotBuilder = $this->createStub(WorldPackSnapshotBuilder::class);
        $snapshotBuilder->method('distributeByPosition')->willReturn($requested);

        $playerRepo = $this->createStub(PlayerRepository::class);
        $playerRepo->method('lowestEligibleAbilities')->willReturn([]);
        $playerRepo->method('countPoolMatching')->willReturnCallback(
            function (?PlayerPosition $position = null, ?int $abilityMin = null, ?int $abilityMax = null, ?string $nationality = null, ?\DateTimeImmutable $bornOnOrBefore = null) use ($available): int {
                // The diagnostic's "available" is the in-band, age-eligible, any-nationality
                // count — the third of its four funnel steps. The others only decorate the
                // page, so they can answer with the same number.
                if ($position === null) {
                    return 0;
                }

                return $available[$position->value] ?? 0;
            }
        );

        $staffRepo = $this->createStub(StaffRepository::class);
        $staffRepo->method('countInPoolByRole')->willReturn(99);

        $scoutRepo = $this->createStub(ScoutRepository::class);
        $scoutRepo->method('countInPool')->willReturn(99);

        $profileRepo = $this->createStub(CountryProfileRepository::class);
        $profileRepo->method('liveCountryCodes')->willReturn(['EN']);

        $diagnostics = new StarterPackDiagnosticService(
            $playerRepo,
            $staffRepo,
            $scoutRepo,
            $starterRepo,
            $profileRepo,
            $poolRepo,
            $snapshotBuilder,
            $starterPack,
        );

        $pool = $this->createStub(MarketPoolService::class);
        $pool->method('generatePlayersInBand')->willReturnCallback(
            function (int $count, PlayerPosition $position, ?string $nationality, array $band, array $ages) use ($deliver): array {
                $this->generated[] = [
                    'count'       => $count,
                    'position'    => $position->value,
                    'nationality' => $nationality,
                    'band'        => $band,
                    'ages'        => $ages,
                ];
                $made = min($deliver ?? $count, $count);

                return ['players' => array_fill(0, $made, new Player()), 'attempts' => $count];
            }
        );

        return new StarterPackPoolWarmService($diagnostics, $starterPack, $poolRepo, $pool, new NullLogger());
    }

    /** Mirrors StarterPackService::resolveAbilityRange()'s authored-ranges branch. */
    private function bandFrom(StarterConfig $config, string $country, int $tier): array
    {
        $ranges = $config->getLeagueAbilityRanges();

        if (isset($ranges[$country][(string) $tier])) {
            return [
                'min'    => (int) $ranges[$country][(string) $tier]['min'],
                'max'    => (int) $ranges[$country][(string) $tier]['max'],
                'source' => 'starterConfig.leagueAbilityRanges',
            ];
        }

        return ['min' => 10, 'max' => 25, 'source' => 'WorldInitializationService::ABILITY_RANGES'];
    }
}
