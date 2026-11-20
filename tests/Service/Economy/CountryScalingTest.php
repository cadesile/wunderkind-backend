<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\GameConfig;
use App\Entity\League;
use App\Entity\Location\CountryProfile;
use App\Entity\StarterConfig;
use App\Enum\EconomyWageModel;
use App\Repository\GameConfigRepository;
use App\Repository\LeagueRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\NpcClubRepository;
use App\Repository\StarterConfigRepository;
use App\Service\Economy\EconomyScaleResolver;
use App\Service\Economy\LeagueSizeResolver;
use App\Service\Economy\WageModelResolver;
use App\Tests\Support\ReferenceSquadDoubles;
use PHPUnit\Framework\TestCase;

/**
 * OWNER RULING 2026-11-20: *"everything should scale. TV, prize, position and parachute."*
 *
 * The authored figures are baseline-country (England) figures, authored once; the country factor
 * is applied where each is resolved. These tests pin the two quantities that had no factor at all
 * — the TV pool and the youth scholarship — and that England, whose factor is exactly 10000, is
 * returned unchanged. Prize, position pot and parachute are pinned in LeaguePayoutResolverTest.
 */
class CountryScalingTest extends TestCase
{
    private const BANDS = [
        '1' => ['min' => 40, 'max' => 90], '2' => ['min' => 30, 'max' => 60],
        '3' => ['min' => 20, 'max' => 40], '4' => ['min' => 15, 'max' => 35],
    ];
    private const SQUADS = [
        '1' => ['playerMin' => 32, 'playerMax' => 35], '2' => ['playerMin' => 28, 'playerMax' => 32],
        '3' => ['playerMin' => 27, 'playerMax' => 30], '4' => ['playerMin' => 25, 'playerMax' => 28],
    ];
    private const TURNOVER = [33_100_000_000, 11_400_000_000, 3_460_000_000, 2_070_000_000];

    // ── TV ───────────────────────────────────────────────────────────────

    public function testTheTvPoolIsScaledByTheCountryFactorAndEnglandIsUntouched(): void
    {
        $resolver = $this->sizeResolver();

        $en = $this->league('EN', 1, 30_000_000_000);
        $sc = $this->league('SC', 1, 30_000_000_000);

        self::assertSame(30_000_000_000, $resolver->tvPoolPenceFor($en), 'England byte-identical');
        self::assertSame(3_000_000_000, $resolver->tvPoolPenceFor($sc), 'Scotland is a tenth');
        self::assertSame(30_000_000_000, $en->getTvPoolPence(), 'the authored pool is not mutated');
    }

    public function testThePerClubShareIsDerivedFromTheScaledPool(): void
    {
        // pool / clubs == tvDeal must hold on the figure a device is given, so both sides scale.
        $sc = $this->league('SC', 1, 30_000_000_000);
        $resolver = $this->sizeResolver(['SC' => [(string) $sc->getId() => 12]]);

        self::assertSame(250_000_000, $resolver->derivedTvDealPence($sc));   // 3,000,000,000 / 12
        self::assertSame(250_000_000, $resolver->tvDealFor($sc));
    }

    public function testAnUnpooledLeagueStillHasItsDirectlyAuthoredDealScaled(): void
    {
        $sc = new League('SC', 1, 'L1');
        $sc->setTvDeal(1_000_000_000);

        self::assertSame(100_000_000, $this->sizeResolver()->tvDealFor($sc));

        $en = new League('EN', 1, 'L1');
        $en->setTvDeal(1_000_000_000);
        self::assertSame(1_000_000_000, $this->sizeResolver()->tvDealFor($en));
    }

    public function testAPooledButEmptyTierReturnsTheCacheWithoutScalingItASecondTime(): void
    {
        // sizeOf() = 0 -> no derivation. The stored column is what syncTvDeal() wrote from the
        // already-scaled derivation, so scaling it again would give a hundredth.
        $sc = $this->league('SC', 1, 30_000_000_000);
        $sc->setTvDeal(250_000_000);

        self::assertSame(250_000_000, $this->sizeResolver()->tvDealFor($sc));
    }

    public function testNullPoolStaysNull(): void
    {
        self::assertNull($this->sizeResolver()->tvPoolPenceFor(new League('SC', 1, 'L1')));
    }

    // ── Youth scholarship ────────────────────────────────────────────────

    public function testTheYouthScholarshipScalesWithTheCountryAndEnglandIsUntouched(): void
    {
        $config = new GameConfig();
        $config->setEconomyBaselineTurnoverPence(self::TURNOVER);
        $config->setEconomyWageModel(EconomyWageModel::TURNOVER);
        $config->setEconomyYouthScholarshipTier1Pence(150_000);
        $config->setEconomyYouthScholarshipFloorPence(2_500);

        $resolver = $this->wageResolver($config);

        // BEFORE this change the two were equal at every tier: the factor cancelled in the
        // `anchor x avg(tier) / avg(tier 1)` ratio and the anchor and floor were absolute.
        self::assertSame(150_000, $resolver->youthScholarshipPence('EN', 1, $config));
        self::assertSame(15_000, $resolver->youthScholarshipPence('SC', 1, $config));

        for ($tier = 1; $tier <= 4; $tier++) {
            $en = $resolver->youthScholarshipPence('EN', $tier, $config);
            $sc = $resolver->youthScholarshipPence('SC', $tier, $config);

            self::assertEqualsWithDelta($en / 10, $sc, 1.0, "tier {$tier}: Scotland is a tenth of England, give or take a penny of rounding");
        }
    }

    // ── plumbing ─────────────────────────────────────────────────────────

    private function league(string $country, int $tier, int $pool): League
    {
        $l = new League($country, $tier, "League {$tier}");
        $l->setTvPoolPence($pool);

        return $l;
    }

    /** @param array<string, array<string, int>> $counts country => (league id => npc club rows) */
    private function sizeResolver(array $counts = []): LeagueSizeResolver
    {
        $npcRepo = $this->createStub(NpcClubRepository::class);
        $npcRepo->method('countsByLeagueForCountry')->willReturnCallback(
            static fn (string $c) => $counts[strtoupper($c)] ?? [],
        );

        $leagueRepo = $this->createStub(LeagueRepository::class);
        $leagueRepo->method('findLowestTierForCountry')->willReturn(new League('XX', 8, 'bottom'));

        return new LeagueSizeResolver($npcRepo, $leagueRepo, $this->scaleResolver($this->createStub(GameConfigRepository::class)));
    }

    private function wageResolver(GameConfig $config): WageModelResolver
    {
        $repo = $this->createStub(GameConfigRepository::class);
        $repo->method('getConfig')->willReturn($config);

        $starter = new StarterConfig();
        $starter->setLeagueAbilityRanges(['EN' => self::BANDS, 'SC' => self::BANDS]);
        $starter->setNpcSquadConfig(self::SQUADS);
        $starterRepo = $this->createStub(StarterConfigRepository::class);
        $starterRepo->method('getConfig')->willReturn($starter);

        return new WageModelResolver(
            $repo,
            $this->scaleResolver($repo, $starterRepo),
            new \App\Service\Economy\TierBandScaleResolver($starterRepo),
        );
    }

    private function scaleResolver(GameConfigRepository $repo, ?StarterConfigRepository $starterRepo = null): EconomyScaleResolver
    {
        $profiles = [
            'EN' => $this->profile('EN', 10000, true),
            'SC' => $this->profile('SC', 1000),
        ];

        $profileRepo = $this->createStub(CountryProfileRepository::class);
        $profileRepo->method('findByCode')->willReturnCallback(static fn (string $c) => $profiles[strtoupper(trim($c))] ?? null);
        $profileRepo->method('findEconomyBaseline')->willReturn($profiles['EN']);

        if ($starterRepo === null) {
            $starterRepo = $this->createStub(StarterConfigRepository::class);
            $starterRepo->method('getConfig')->willReturn(new StarterConfig());
        }

        return new EconomyScaleResolver(
            $repo,
            $starterRepo,
            $profileRepo,
            ReferenceSquadDoubles::poolConfigRepository(),
            ReferenceSquadDoubles::playerGeneration(),
        );
    }

    private function profile(string $code, int $factorBp, bool $baseline = false): CountryProfile
    {
        $p = new CountryProfile($code, $code);
        $p->setEconomyFactorBp($factorBp);
        $p->setIsEconomyBaseline($baseline);

        return $p;
    }
}
