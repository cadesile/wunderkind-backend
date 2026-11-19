<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\GameConfig;
use App\Entity\Location\CountryProfile;
use App\Entity\StarterConfig;
use App\Repository\GameConfigRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\StarterConfigRepository;
use App\Service\Economy\EconomyScaleResolver;
use App\Service\Economy\TicketPriceResolver;
use App\Tests\Support\ReferenceSquadDoubles;
use PHPUnit\Framework\TestCase;

/**
 * The ticket price the backend publishes, and the zero that means "nobody authored one".
 *
 * ── WHY ZERO CARRIES SO MUCH WEIGHT HERE ──────────────────────────────────────────────────
 *
 * Every other money figure in this chain has a flat predecessor to fall back to. A ticket
 * price has none — the backend has never held one — so 0 has to mean "unauthored, use your own
 * figure", and the app answers that zero with a 2500p constant compiled into the release.
 *
 * That makes the zero the most consequential value in the file, in BOTH directions:
 *
 *  - a 0 that should have been a price hands every club in the division the flat constant
 *    back, which is the defect this table exists to fix;
 *  - a price that TRUNCATES to 0 reads as "unauthored" rather than "very cheap", so the
 *    cheapest possible configuration produces the most expensive possible reading.
 *
 * So zero propagates deliberately from every input that means "off", and a real price is
 * floored at a penny rather than being allowed to reach zero by division.
 *
 * ── SAME SHAPE AS `SponsorBandResolver` ───────────────────────────────────────────────────
 *
 * Override, then ratio, then nothing; country factor once, last; one `intdiv` at the end.
 * `SponsorTierRatioTest` pins the sponsor half of the same three properties.
 */
class TicketPriceResolverTest extends TestCase
{
    // ── Zero stays zero, at every step ───────────────────────────────────

    public function testTheShippedStateIsZeroAtEveryTier(): void
    {
        // Inert: nothing authored, so every division keeps using the app's own figure.
        $config   = new GameConfig();
        $resolver = $this->resolver($config);

        foreach (range(1, 8) as $tier) {
            self::assertSame(0, $resolver->priceFor($tier, 'EN', $config), "tier {$tier}");
        }
    }

    public function testARatioLadderWithNoBaseResolvesToZeroEverywhere(): void
    {
        // THE PROPERTY THAT MAKES THE LADDER SAFE TO AUTHOR FIRST. Step 6 of this pass
        // proposes ratios before the base figure is agreed; nothing may move until it lands.
        $config = new GameConfig();
        $config->setEconomyTicketPriceTierRatioBp([10000, 8000, 6500, 5000, 4000, 3200, 2600, 2200]);

        $resolver = $this->resolver($config);

        foreach (range(1, 8) as $tier) {
            self::assertSame(0, $resolver->priceFor($tier, 'EN', $config), "tier {$tier}");
        }
    }

    public function testAZeroRatioZeroesOnlyThatTier(): void
    {
        $config = new GameConfig();
        $config->setEconomyTicketPriceTier1Pence(5_000);
        $config->setEconomyTicketPriceTierRatioBp([10000, 0, 5000, 10000, 10000, 10000, 10000, 10000]);

        $resolver = $this->resolver($config);

        self::assertSame(5_000, $resolver->priceFor(1, 'EN', $config));
        self::assertSame(0,     $resolver->priceFor(2, 'EN', $config));
        self::assertSame(2_500, $resolver->priceFor(3, 'EN', $config));
    }

    public function testACountryWhoseFactorTruncatesToZeroResolvesToZero(): void
    {
        // A country factor cannot BE zero — `CountryProfile::setEconomyFactorBp()` floors it
        // at one basis point on purpose. It can still RESOLVE to zero, because
        // `countryFactorBp()` reports it as basis points of the baseline and that division
        // truncates: 1bp against a baseline of 20000bp is 0.
        //
        // Zero is the right answer there and not a penny, because such a country has no
        // economy at all in this chain — `scalePence()` already resolves every wage, balance
        // and ceiling in it to nothing. A one-penny ticket in a country where nobody is paid
        // would be the only live figure in it.
        $config = new GameConfig();
        $config->setEconomyTicketPriceTier1Pence(5_000);

        $resolver = $this->resolver($config, [
            'EN' => $this->profile('EN', 20000, true),
            'XX' => $this->profile('XX', 0), // floored to 1bp on write
        ]);

        self::assertSame(0, $resolver->priceFor(1, 'XX', $config));
    }

    public function testTierZeroAndTiersPastEightResolveToZero(): void
    {
        // Tier 0 means "no tier", as in `SponsorBandResolver::bandFor()`. There is no
        // division-less ticket price, so it is unauthored rather than tier 1's figure.
        $config = new GameConfig();
        $config->setEconomyTicketPriceTier1Pence(5_000);

        $resolver = $this->resolver($config);

        self::assertSame(0, $resolver->priceFor(0, 'EN', $config));
        self::assertSame(0, $resolver->priceFor(9, 'EN', $config));
        self::assertSame(0, $resolver->priceFor(-1, 'EN', $config));
    }

    // ── A real price never truncates to unauthored ───────────────────────

    public function testAVeryCheapPriceIsFlooredAtAPennyNotZeroed(): void
    {
        // The dangerous direction. 1p is "very cheap"; 0 would hand the app its 2500p
        // constant back, which is the most expensive possible reading of the cheapest
        // possible price.
        $config = new GameConfig();
        $config->setEconomyTicketPriceTier1Pence(100);
        $config->setEconomyTicketPriceTierRatioBp([10000, 10000, 10000, 10000, 10000, 10000, 10000, 1]);

        $resolver = $this->resolver($config, ['EN' => $this->profile('EN', 10000, true), 'XX' => $this->profile('XX', 1)]);

        self::assertSame(1, $resolver->priceFor(8, 'XX', $config));
    }

    // ── The ladder ───────────────────────────────────────────────────────

    public function testEachTierIsTheBaseTimesItsRatio(): void
    {
        $config = new GameConfig();
        $config->setEconomyTicketPriceTier1Pence(4_000);
        $config->setEconomyTicketPriceTierRatioBp([10000, 8000, 6500, 5000, 4000, 3200, 2600, 2200]);

        $resolver = $this->resolver($config);

        self::assertSame(
            [1 => 4_000, 2 => 3_200, 3 => 2_600, 4 => 2_000, 5 => 1_600, 6 => 1_280, 7 => 1_040, 8 => 880],
            $resolver->pricesFor('EN', $config),
        );
    }

    public function testPricesForRespectsAShortPyramid(): void
    {
        // Scotland has four tiers, not eight — the thing every consumer of this chain has to
        // be ready for.
        $config = new GameConfig();
        $config->setEconomyTicketPriceTier1Pence(4_000);

        self::assertSame([1, 2, 3, 4], array_keys($this->resolver($config)->pricesFor('SC', $config, 4)));
    }

    // ── The override wins, and is scaled by the country ──────────────────

    public function testAnExplicitPerTierPriceOverridesTheLadder(): void
    {
        $config = new GameConfig();
        $config->setEconomyTicketPriceTier1Pence(4_000);
        $config->setEconomyTicketPriceTierRatioBp([10000, 5000, 5000, 5000, 5000, 5000, 5000, 5000]);
        $config->setEconomyTicketPricePerTierPence([0, 3_300, 0, 0, 0, 0, 0, 0]);

        $resolver = $this->resolver($config);

        self::assertSame(3_300, $resolver->priceFor(2, 'EN', $config), 'the authored row, verbatim');
        self::assertSame(2_000, $resolver->priceFor(3, 'EN', $config), 'the neighbour still rides the ladder');
    }

    public function testTheCountryFactorAppliesToAnOverrideToo(): void
    {
        // BASE-YEAR EVERYWHERE, including the override: these are published as resolved,
        // country-scaled figures, and the app applies no factor of its own.
        $config = new GameConfig();
        $config->setEconomyTicketPricePerTierPence([5_000, 0, 0, 0, 0, 0, 0, 0]);

        $resolver = $this->resolver($config, ['EN' => $this->profile('EN', 10000, true), 'ES' => $this->profile('ES', 2000)]);

        self::assertSame(5_000, $resolver->priceFor(1, 'EN', $config));
        self::assertSame(1_000, $resolver->priceFor(1, 'ES', $config));
    }

    public function testNoCountryMeansNoScaling(): void
    {
        $config = new GameConfig();
        $config->setEconomyTicketPriceTier1Pence(5_000);

        self::assertSame(5_000, $this->resolver($config)->priceFor(1, '', $config));
    }

    // ── One truncation, at the end ───────────────────────────────────────

    public function testTheRatioAndTheCountryFactorTruncateOnceBetweenThem(): void
    {
        // As in `SponsorTierRatioTest`: dividing after the ratio and again after the factor
        // loses a penny twice, and the published price then disagrees with itself depending on
        // which caller resolved it.
        $config = new GameConfig();
        $config->setEconomyTicketPriceTier1Pence(1_234_567);
        $config->setEconomyTicketPriceTierRatioBp([10000, 12345, 10000, 10000, 10000, 10000, 10000, 10000]);

        $resolver = $this->resolver($config, ['EN' => $this->profile('EN', 10000, true), 'ES' => $this->profile('ES', 6789)]);

        $onceOnly = intdiv(1_234_567 * 12345 * 6789, 10000 * 10000);
        $twice    = intdiv(intdiv(1_234_567 * 12345, 10000) * 6789, 10000);

        self::assertNotSame($twice, $onceOnly, 'the fixture no longer distinguishes the two orders');
        self::assertSame($onceOnly, $resolver->priceFor(2, 'ES', $config));
    }

    // ── Storage normalisation ────────────────────────────────────────────

    public function testTheRatioTableShipsAtParityAndIsAlwaysEightEntries(): void
    {
        self::assertSame(
            [10000, 10000, 10000, 10000, 10000, 10000, 10000, 10000],
            (new GameConfig())->getEconomyTicketPriceTierRatioBp(),
        );

        $config = (new GameConfig())->setEconomyTicketPriceTierRatioBp([10000, 5000]);

        self::assertSame([10000, 5000, 10000, 10000, 10000, 10000, 10000, 10000], $config->getEconomyTicketPriceTierRatioBp());
    }

    public function testTheBaseShipsAtZeroAndCannotGoNegative(): void
    {
        self::assertSame(0, (new GameConfig())->getEconomyTicketPriceTier1Pence());
        self::assertSame(0, (new GameConfig())->setEconomyTicketPriceTier1Pence(-1)->getEconomyTicketPriceTier1Pence());
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /** @param array<string, CountryProfile> $profiles */
    private function resolver(GameConfig $config, array $profiles = []): TicketPriceResolver
    {
        $gameConfigRepo = $this->createStub(GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn($config);

        $starterRepo = $this->createStub(StarterConfigRepository::class);
        $starterRepo->method('getConfig')->willReturn(new StarterConfig());

        $profileRepo = $this->createStub(CountryProfileRepository::class);
        $profileRepo->method('findByCode')->willReturnCallback(
            static fn (string $c) => $profiles[strtoupper(trim($c))] ?? null,
        );
        $profileRepo->method('findEconomyBaseline')->willReturnCallback(
            static function () use ($profiles) {
                foreach ($profiles as $p) {
                    if ($p->isEconomyBaseline()) {
                        return $p;
                    }
                }

                return null;
            },
        );

        return new TicketPriceResolver($gameConfigRepo, new EconomyScaleResolver($gameConfigRepo, $starterRepo, $profileRepo, ReferenceSquadDoubles::poolConfigRepository(), ReferenceSquadDoubles::playerGeneration()));
    }

    private function profile(string $code, int $factorBp, bool $baseline = false): CountryProfile
    {
        $p = new CountryProfile($code, $code);
        $p->setEconomyFactorBp($factorBp);
        $p->setIsEconomyBaseline($baseline);

        return $p;
    }
}
