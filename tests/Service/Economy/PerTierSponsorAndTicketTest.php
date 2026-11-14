<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\GameConfig;
use App\Entity\League;
use App\Entity\Location\CountryProfile;
use App\Entity\Sponsor;
use App\Entity\StarterConfig;
use App\Enum\CompanySize;
use App\Repository\GameConfigRepository;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\StarterConfigRepository;
use App\Service\Economy\EconomyScaleResolver;
use App\Service\Economy\SponsorBandResolver;
use PHPUnit\Framework\TestCase;

/**
 * Per-tier sponsor bands and ticket prices.
 *
 * ── WHAT IS AT RISK ───────────────────────────────────────────────────────────────────────
 *
 * These are authored tables that OVERRIDE a shipped flat behaviour, so the dangerous states
 * are the half-configured ones, not the fully-authored one:
 *
 *  - a tier left out must resolve **exactly** as it did before the column existed, or filling
 *    the table in one division at a time is impossible;
 *  - a tier authored for only one or two company sizes must be ignored **entirely**, because a
 *    row that falls back for `large` and not for `small` is the hardest kind of band to debug;
 *  - the country factor must still apply **on top** of a per-tier band, or the table would have
 *    to be re-authored per country — which is the whole thing the base-year rule exists to
 *    avoid.
 *
 * And the one that would be silent: `rollLeagueSponsors()` must pass the league's own tier.
 * Forgetting it there would let a table be authored, published, and then ignored at the only
 * point that actually spends money.
 */
class PerTierSponsorAndTicketTest extends TestCase
{
    /** The live flat England bands, as the six columns hold them. */
    private const FLAT = [
        'small'  => [5_600_000, 8_200_000],
        'medium' => [8_200_000, 13_400_000],
        'large'  => [25_000_000, 80_000_000],
    ];

    // ── Inert until authored ─────────────────────────────────────────────

    public function testAnUnauthoredTableLeavesEveryTierOnTheFlatBands(): void
    {
        // The shipped state. Sponsorship must be byte-identical to before the column existed.
        $config   = $this->config();
        $resolver = $this->resolver($config);

        foreach (range(1, 8) as $tier) {
            self::assertSame(self::FLAT['large'], $resolver->bandFor(CompanySize::LARGE, 'EN', $config, $tier), "tier {$tier}");
        }
    }

    public function testATierLeftOutOfAnAuthoredTableStillUsesTheFlatBands(): void
    {
        // THE PROPERTY THAT MAKES THE TABLE FILLABLE ONE DIVISION AT A TIME. Authoring tier 1
        // must not move tier 2.
        $config = $this->config();
        $config->setEconomySponsorBandsPerTierPence(['1' => $this->row(1_000, 2_000)]);

        $resolver = $this->resolver($config);

        self::assertSame([1_000, 2_000], $resolver->bandFor(CompanySize::LARGE, 'EN', $config, 1));
        self::assertSame(self::FLAT['large'], $resolver->bandFor(CompanySize::LARGE, 'EN', $config, 2));
    }

    public function testTierZeroMeansNoTierAndResolvesFlat(): void
    {
        // `bandFor()` defaults to tier 0 for callers with no league in hand, and the published
        // country-level block relies on that resolving to the flat bands.
        $config = $this->config();
        $config->setEconomySponsorBandsPerTierPence(['1' => $this->row(1_000, 2_000)]);

        self::assertSame(self::FLAT['large'], $this->resolver($config)->bandFor(CompanySize::LARGE, 'EN', $config));
    }

    // ── All three sizes or none ──────────────────────────────────────────

    public function testAPartialTierRowIsDiscardedEntirely(): void
    {
        // A row with only `large` would fall back for small and medium and not for large —
        // three bands from two different tables, which nobody could debug from the output.
        $config = $this->config();
        $config->setEconomySponsorBandsPerTierPence(['3' => ['large' => [1_000, 2_000]]]);

        self::assertSame([], $config->getEconomySponsorBandsPerTierPence());
        self::assertSame(self::FLAT['large'], $this->resolver($config)->bandFor(CompanySize::LARGE, 'EN', $config, 3));
    }

    public function testACompleteRowIsKept(): void
    {
        $config = $this->config();
        $config->setEconomySponsorBandsPerTierPence(['3' => $this->row(1_000, 2_000)]);

        // INT keys, not string: PHP coerces a numeric string array key to an integer, so
        // `$out['3']` is `$out[3]` and there is nothing the setter can do about it. It still
        // JSON-encodes as an object rather than a list, because the keys start at 1.
        self::assertSame([3], array_keys($config->getEconomySponsorBandsPerTierPence()));
    }

    // ── The country factor still applies on top ──────────────────────────

    public function testAPerTierBandIsStillScaledByTheCountryFactor(): void
    {
        // THE BASE-YEAR RULE. If this failed, the per-tier table would have to be re-authored
        // for every country — which is exactly what the factor chain exists to avoid.
        $config = $this->config();
        $config->setEconomySponsorBandsPerTierPence(['1' => $this->row(1_000_000, 2_000_000)]);

        $resolver = $this->resolver($config, ['EN' => $this->profile('EN', 10000, true), 'ES' => $this->profile('ES', 2000)]);

        self::assertSame([1_000_000, 2_000_000], $resolver->bandFor(CompanySize::LARGE, 'EN', $config, 1));
        self::assertSame([200_000, 400_000],     $resolver->bandFor(CompanySize::LARGE, 'ES', $config, 1));
    }

    // ── The roll must use the league's own tier ──────────────────────────

    public function testRollLeagueSponsorsUsesTheLeaguesOwnTier(): void
    {
        // THE SILENT FAILURE. A table authored, published, and then ignored at the only point
        // that spends money — because the one caller forgot to pass the tier.
        $config = $this->config();
        $config->setEconomySponsorBandsPerTierPence(['7' => $this->row(4_200, 4_200)]);

        $league  = new League('EN', 7, 'League 7');
        $sponsor = new Sponsor('Probe Co');
        $sponsor->setSize(CompanySize::LARGE);
        $league->addSponsor($sponsor);

        self::assertSame(4_200, $this->resolver($config)->rollLeagueSponsors($league, $config));
    }

    // ── Normalisation ────────────────────────────────────────────────────

    public function testATransposedPairIsNormalisedOnWrite(): void
    {
        $config = $this->config();
        $config->setEconomySponsorBandsPerTierPence(['1' => ['small' => [9, 1], 'medium' => [9, 1], 'large' => [9, 1]]]);

        self::assertSame([1, 9], $config->getEconomySponsorBandsPerTierPence()['1']['large']);
    }

    public function testTiersOutsideOneToEightAreDropped(): void
    {
        $config = $this->config();
        $config->setEconomySponsorBandsPerTierPence(['0' => $this->row(1, 2), '9' => $this->row(1, 2), '4' => $this->row(1, 2)]);

        self::assertSame([4], array_keys($config->getEconomySponsorBandsPerTierPence()));
    }

    // ── Ticket price ─────────────────────────────────────────────────────

    public function testTicketPriceShipsAsEightZeros(): void
    {
        // 0 is "not authored — use your own figure", per tier. There is no flat predecessor to
        // fall back to, which is why the zero has to mean that rather than "free".
        self::assertSame([0, 0, 0, 0, 0, 0, 0, 0], (new GameConfig())->getEconomyTicketPricePerTierPence());
    }

    public function testTicketPriceIsAlwaysEightEntries(): void
    {
        // Read by index, so a short list would make a deep country's lower tiers resolve to
        // null mid-chain — the reasoning normaliseTierBpList() already carries.
        $config = (new GameConfig())->setEconomyTicketPricePerTierPence([4500, 3200]);

        self::assertCount(8, $config->getEconomyTicketPricePerTierPence());
        self::assertSame([4500, 3200, 0, 0, 0, 0, 0, 0], $config->getEconomyTicketPricePerTierPence());
    }

    public function testTicketPriceClampsNegativesAndTruncatesALongList(): void
    {
        $config = (new GameConfig())->setEconomyTicketPricePerTierPence([-5, 1, 2, 3, 4, 5, 6, 7, 8, 9]);

        self::assertSame([0, 1, 2, 3, 4, 5, 6, 7], $config->getEconomyTicketPricePerTierPence());
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    private function row(int $lo, int $hi): array
    {
        return ['small' => [$lo, $hi], 'medium' => [$lo, $hi], 'large' => [$lo, $hi]];
    }

    private function config(): GameConfig
    {
        $config = new GameConfig();
        $config->setSmallSponsorMin(self::FLAT['small'][0])->setSmallSponsorMax(self::FLAT['small'][1]);
        $config->setMediumSponsorMin(self::FLAT['medium'][0])->setMediumSponsorMax(self::FLAT['medium'][1]);
        $config->setLargeSponsorMin(self::FLAT['large'][0])->setLargeSponsorMax(self::FLAT['large'][1]);

        return $config;
    }

    /** @param array<string, CountryProfile> $profiles */
    private function resolver(GameConfig $config, array $profiles = []): SponsorBandResolver
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

        return new SponsorBandResolver($gameConfigRepo, new EconomyScaleResolver($gameConfigRepo, $starterRepo, $profileRepo));
    }

    private function profile(string $code, int $factorBp, bool $baseline = false): CountryProfile
    {
        $p = new CountryProfile($code, $code);
        $p->setEconomyFactorBp($factorBp);
        $p->setIsEconomyBaseline($baseline);

        return $p;
    }
}
