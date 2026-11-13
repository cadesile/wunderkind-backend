<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\StarterConfig;
use App\Repository\GameConfigRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\StarterConfigRepository;
use App\Service\Economy\EconomyConfigPublisher;
use App\Service\Economy\EconomyScaleResolver;
use App\Service\Economy\SponsorBandResolver;
use App\Service\Economy\WageModelResolver;
use App\Entity\GameConfig;
use PHPUnit\Framework\TestCase;

/**
 * `leaguePlayerAbilityRanges` must be the table that prices players, and nothing else.
 *
 * ── THE DEFECT THIS PINS, WHICH COST A RELEASE-GATE VERDICT ───────────────────────────────
 *
 * There were two ability tables with nearly the same name. `starter_config.league_ability_ranges`
 * priced and generated every player; `game_config.league_player_ability_ranges` was published to
 * the app and had **no server-side consumer at all**. They disagreed at every England tier —
 * 55-95 against 40-90 at tier 1, 5-15 against 2-7 at tier 8 — and nine app-side readers used
 * the published one for recruiting, squad thinning, culling and contract renewal. Squads were
 * built from one table and judged against the other, and the app's economy gate was built on
 * the wrong one and had to withdraw its conclusion.
 *
 * So the property here is **directional**: the published table is derived from the pricing
 * table, never authored beside it. A test that merely compared two stored columns would pass
 * the moment somebody hand-synced them and fail again on the next edit. This asserts the
 * publisher reads through, so there is only ever one table to author.
 */
class AbilityRangeParityTest extends TestCase
{
    public function testEveryPublishedCountryComesFromTheAuthoredTable(): void
    {
        // No published country may be invented. The reverse direction — authored but
        // unpublished — is covered below.
        $starter   = $this->starterWith([
            'EN' => [1 => [40, 90], 8 => [2, 7]],
            'SC' => [1 => [75, 95]],
        ]);
        $published = $this->publisher($starter)->leaguePlayerAbilityRanges();

        self::assertSame(['EN', 'SC'], array_column($published, 'country'));
    }

    public function testEveryAuthoredBandIsPublishedUnchanged(): void
    {
        // THE DIRECTIONAL ASSERTION. Walk the authored table and demand the published one
        // matches it cell for cell.
        $authored = ['EN' => [1 => [40, 90], 4 => [15, 35], 8 => [2, 7]]];
        $starter  = $this->starterWith($authored);

        $published = [];
        foreach ($this->publisher($starter)->leaguePlayerAbilityRanges() as $row) {
            foreach ($row['leagues'] as $l) {
                $published[$row['country']][$l['tier']] = [$l['min'], $l['max']];
            }
        }

        self::assertSame($authored, $published);
    }

    public function testTheTierNumberIsPublishedOnEveryRow(): void
    {
        // Because Scotland has four tiers and everything else has eight, a consumer has to be
        // able to read the tier rather than infer it from position.
        $published = $this->publisher($this->starterWith(['SC' => [1 => [75, 95], 4 => [45, 65]]]))
            ->leaguePlayerAbilityRanges();

        self::assertSame([1, 4], array_column($published[0]['leagues'], 'tier'));
    }

    public function testAShortCountryIsPublishedShortRatherThanPaddedToEight(): void
    {
        // Padding Scotland to eight tiers would invent bands for divisions that do not exist
        // — and the padding would come from the const fallback, which is the very thing that
        // caused this defect.
        $published = $this->publisher($this->starterWith(['SC' => [1 => [75, 95], 2 => [65, 85]]]))
            ->leaguePlayerAbilityRanges();

        self::assertCount(2, $published[0]['leagues']);
    }

    // ── Absent means absent ──────────────────────────────────────────────

    public function testACountryWithNoAuthoredRowIsOmittedNotFilledFromTheConstFallback(): void
    {
        // THE DECISION MOST LIKELY TO BE "HELPFULLY" REVERSED. `abilityBand()` falls back to
        // WorldInitializationService::ABILITY_RANGES (75-95 ... 10-25) for an unauthored
        // country, because the pricing path must return something. Publishing that fallback
        // would put bands in the response that no generator uses for that country — exactly
        // the defect being fixed, in a new place. Omission keeps "absent" meaning "not
        // authored" and leaves the consumer's own fallback to decide.
        $published = $this->publisher($this->starterWith(['EN' => [1 => [40, 90]]]))
            ->leaguePlayerAbilityRanges();

        self::assertSame(['EN'], array_column($published, 'country'));
        self::assertNotContains('ZZ', array_column($published, 'country'));
    }

    public function testAnEmptyAuthoredTablePublishesAnEmptyList(): void
    {
        self::assertSame([], $this->publisher($this->starterWith([]))->leaguePlayerAbilityRanges());
    }

    public function testARowWithNoUsableMinIsNotAnAuthoredBand(): void
    {
        // Same guard abilityBand() applies. A zero min is an empty slot, and publishing it as
        // 0-0 would price a tier at nothing rather than saying nothing about it.
        $starter = new StarterConfig();
        $starter->setLeagueAbilityRanges([
            'EN' => ['1' => ['min' => 40, 'max' => 90], '2' => ['min' => 0, 'max' => 0]],
        ]);

        $published = $this->publisher($starter)->leaguePlayerAbilityRanges();

        self::assertSame([1], array_column($published[0]['leagues'], 'tier'));
    }

    // ── Normalisation agrees with the pricing path ───────────────────────

    public function testATransposedPairIsNormalisedTheSameWayThePricingPathDoes(): void
    {
        // Both halves are authored independently in the admin, so a swapped pair is a real
        // state of the row. If the publisher and `abilityBand()` disagreed about it, the two
        // tables would diverge again through the back door.
        $starter = new StarterConfig();
        $starter->setLeagueAbilityRanges(['EN' => ['1' => ['min' => 90, 'max' => 40]]]);

        $resolver  = $this->resolver($starter);
        $published = $this->publisher($starter, $resolver)->leaguePlayerAbilityRanges();
        $priced    = $resolver->abilityBand('EN', 1);

        self::assertSame($priced['min'], $published[0]['leagues'][0]['min']);
        self::assertSame($priced['max'], $published[0]['leagues'][0]['max']);
        self::assertSame(40, $published[0]['leagues'][0]['min']);
    }

    public function testCountryCodesAreUppercasedAndSorted(): void
    {
        $published = $this->publisher($this->starterWith(['sc' => [1 => [75, 95]], 'en' => [1 => [40, 90]]]))
            ->leaguePlayerAbilityRanges();

        self::assertSame(['EN', 'SC'], array_column($published, 'country'));
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /** @param array<string, array<int, array{0:int,1:int}>> $byCountry */
    private function starterWith(array $byCountry): StarterConfig
    {
        $ranges = [];
        foreach ($byCountry as $code => $tiers) {
            foreach ($tiers as $tier => [$min, $max]) {
                $ranges[$code][(string) $tier] = ['min' => $min, 'max' => $max];
            }
        }

        $config = new StarterConfig();
        $config->setLeagueAbilityRanges($ranges);

        return $config;
    }

    private function resolver(StarterConfig $starter): EconomyScaleResolver
    {
        $gameConfigRepo = $this->createStub(GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn(new GameConfig());

        $starterRepo = $this->createStub(StarterConfigRepository::class);
        $starterRepo->method('getConfig')->willReturn($starter);

        $profileRepo = $this->createStub(CountryProfileRepository::class);
        $profileRepo->method('findByCode')->willReturn(null);
        $profileRepo->method('findEconomyBaseline')->willReturn(null);

        return new EconomyScaleResolver($gameConfigRepo, $starterRepo, $profileRepo);
    }

    private function publisher(StarterConfig $starter, ?EconomyScaleResolver $resolver = null): EconomyConfigPublisher
    {
        $resolver       = $resolver ?? $this->resolver($starter);
        $gameConfigRepo = $this->createStub(GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn(new GameConfig());

        return new EconomyConfigPublisher(
            $gameConfigRepo,
            $resolver,
            new WageModelResolver($gameConfigRepo, $resolver),
            new SponsorBandResolver($gameConfigRepo, $resolver),
        );
    }
}
