<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\GameConfig;
use App\Entity\StarterConfig;
use App\Repository\GameConfigRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\StarterConfigRepository;
use App\Service\Economy\EconomyConfigPublisher;
use App\Service\Economy\EconomyScaleResolver;
use App\Service\Economy\SponsorBandResolver;
use App\Service\Economy\WageModelResolver;
use PHPUnit\Framework\TestCase;

/**
 * `economyTierBaseline.source` — the one word that stops a no-country read passing for England.
 *
 * ── THE INCIDENT THIS EXISTS BECAUSE OF ───────────────────────────────────────────────────
 *
 * `?country=` is optional on `GET /api/game-config`, and a request without it does **not**
 * return the baseline country's figures. It resolves at the const fallback: `findByCode('')`
 * finds no profile, every factor lands at parity, and the ability bands come from
 * `WorldInitializationService::ABILITY_RANGES` rather than any country's authored table. The
 * two responses are the same shape and wildly different numbers — tier-1 `wageScaleMicroPence`
 * is 217,517,151 with no country against England's 390,404,650.
 *
 * The app's release gate was built on the no-country read. It concluded every tier was solvent;
 * the conclusion had to be withdrawn. `country: null` was already published and had already
 * failed to prevent it, because null reads as "country-agnostic, therefore fine" rather than
 * "these are nobody's figures".
 *
 * So the property under test is not that the field exists — it is that **the two reads are
 * distinguishable by a value a consumer can refuse on**, and that the field is always present
 * so it can never be mistaken for a warning that only appears when something is wrong.
 */
class TierBaselineSourceTest extends TestCase
{
    public function testANoCountryReadIsLabelledFallback(): void
    {
        $block = $this->publisher()->clientBlock(new GameConfig(), null)['economyTierBaseline'];

        self::assertSame('fallback', $block['source']);
        self::assertNull($block['country'], 'and country stays null, as it always was');
    }

    public function testAnEmptyStringIsAlsoFallbackRatherThanACountry(): void
    {
        // `?country=` present but blank is the same read as absent — a query string is easy to
        // build with an empty value, and it must not look like a resolved country.
        foreach (['', '   '] as $blank) {
            $block = $this->publisher()->clientBlock(new GameConfig(), $blank)['economyTierBaseline'];

            self::assertSame('fallback', $block['source'], var_export($blank, true));
        }
    }

    public function testAResolvedCountryIsLabelledCountryAndEchoesItsCode(): void
    {
        $block = $this->publisher()->clientBlock(new GameConfig(), 'EN')['economyTierBaseline'];

        self::assertSame('country', $block['source']);
        self::assertSame('EN', $block['country']);
    }

    public function testTheCodeIsNormalisedButStillCountsAsResolved(): void
    {
        // A lowercase or padded code is a resolved country, not a fallback — refusing it would
        // make the guard fire on a correct request.
        $block = $this->publisher()->clientBlock(new GameConfig(), ' en ')['economyTierBaseline'];

        self::assertSame('country', $block['source']);
        self::assertSame('EN', $block['country']);
    }

    public function testTheFieldIsAlwaysPresent(): void
    {
        // Always, so a consumer can require it. A key that appeared only on a fallback read
        // would be a warning rather than a contract, and `?? 'country'` would quietly undo it.
        foreach ([null, '', 'EN', 'SC'] as $country) {
            $block = $this->publisher()->clientBlock(new GameConfig(), $country)['economyTierBaseline'];

            self::assertArrayHasKey('source', $block, var_export($country, true));
            self::assertContains($block['source'], ['country', 'fallback']);
        }
    }

    public function testTheKeySetIsIdenticalEitherWay(): void
    {
        // The publisher's standing rule: same keys whatever the arguments, only the values
        // change. Adding `source` must not break it.
        self::assertSame(
            array_keys($this->publisher()->clientBlock(new GameConfig(), null)['economyTierBaseline']),
            array_keys($this->publisher()->clientBlock(new GameConfig(), 'EN')['economyTierBaseline']),
        );
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    private function publisher(): EconomyConfigPublisher
    {
        $gameConfigRepo = $this->createStub(GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn(new GameConfig());

        $starterRepo = $this->createStub(StarterConfigRepository::class);
        $starterRepo->method('getConfig')->willReturn(new StarterConfig());

        $profileRepo = $this->createStub(CountryProfileRepository::class);
        $profileRepo->method('findByCode')->willReturn(null);
        $profileRepo->method('findEconomyBaseline')->willReturn(null);

        $scale = new EconomyScaleResolver($gameConfigRepo, $starterRepo, $profileRepo);

        return new EconomyConfigPublisher(
            $gameConfigRepo,
            $scale,
            new WageModelResolver($gameConfigRepo, $scale),
            new SponsorBandResolver($gameConfigRepo, $scale),
        );
    }
}
