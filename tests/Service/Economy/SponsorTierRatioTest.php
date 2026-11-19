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
use App\Tests\Support\ReferenceSquadDoubles;
use PHPUnit\Framework\TestCase;

/**
 * The sponsor ratio table: a tier-1 base plus eight ratios, and what each tier is worth.
 *
 * ── WHAT THIS PINS THAT `PerTierSponsorAndTicketTest` DOES NOT ────────────────────────────
 *
 * That test covers the EXPLICIT per-tier table — 48 authored figures, and the half-configured
 * states around them. This covers the second way a pyramid gets priced: the six flat columns
 * as the tier-1 band, scaled by `economySponsorTierRatioBp`. The figures a reviewer actually
 * argues about are the SHARES — what tier 8 earns against tier 1 — so those are what is
 * asserted here, rather than only that a multiplication happened.
 *
 * ── THE THREE WAYS THIS GOES WRONG SILENTLY ───────────────────────────────────────────────
 *
 *  1. **The ratio applied to an override row.** The explicit table is somebody's resolved
 *     answer for a division. Scaling it again would mean a hand-authored tier moved the moment
 *     a ratio was retuned, and no reading of the two tables would tell you which figure you
 *     were going to get.
 *  2. **The ratio applied at tier 0.** `bandFor()` defaults to tier 0 for callers with no
 *     league in hand, and the published country-level `sponsorBandsPence` relies on that
 *     resolving to the unscaled flat band. A ratio leaking in there would re-price a key that
 *     a released client already reads.
 *  3. **Truncating twice.** The ratio and the country factor are one chain with one `intdiv`
 *     at the end. Dividing after each step loses a penny twice, and the published band then
 *     disagrees with the rolled band by one — the kind of difference nobody can reproduce.
 *
 * The tier factor staying out of this chain is pinned next door, in
 * `SponsorBandResolverTest::testTheTierFactorIsDeliberatelyNOTApplied()`.
 */
class SponsorTierRatioTest extends TestCase
{
    /** The live flat England bands, as the six columns hold them. */
    private const FLAT = [
        'small'  => [5_600_000, 8_200_000],
        'medium' => [8_200_000, 13_400_000],
        'large'  => [25_000_000, 80_000_000],
    ];

    // ── Inert at parity ──────────────────────────────────────────────────

    public function testTheTableShipsAtParityAndIsInert(): void
    {
        self::assertSame(
            [10000, 10000, 10000, 10000, 10000, 10000, 10000, 10000],
            (new GameConfig())->getEconomySponsorTierRatioBp(),
        );
    }

    public function testParityRatiosLeaveEveryTierOnTheFlatBand(): void
    {
        // THE SHIPPED STATE, and the one that has to be byte-identical: a parity ratio must not
        // cost a penny to the truncation, at any tier, for any size.
        $config   = $this->config();
        $resolver = $this->resolver($config);

        foreach (range(1, 8) as $tier) {
            foreach (CompanySize::cases() as $size) {
                self::assertSame(
                    self::FLAT[$size->value],
                    $resolver->bandFor($size, 'EN', $config, $tier),
                    "tier {$tier} {$size->value}",
                );
            }
        }
    }

    // ── The shares ───────────────────────────────────────────────────────

    public function testEachTierEarnsItsAuthoredShareOfTheTierOneBand(): void
    {
        // The figures a reviewer argues about. A ladder down to 4% at tier 8 is the shape this
        // pass proposes; what matters is that the resolved band IS the authored share.
        $config = $this->config();
        $config->setEconomySponsorTierRatioBp([10000, 5000, 2500, 1500, 1000, 700, 500, 400]);

        $resolver = $this->resolver($config);

        $expected = [
            1 => [25_000_000, 80_000_000],
            2 => [12_500_000, 40_000_000],
            3 => [6_250_000,  20_000_000],
            4 => [3_750_000,  12_000_000],
            5 => [2_500_000,  8_000_000],
            6 => [1_750_000,  5_600_000],
            7 => [1_250_000,  4_000_000],
            8 => [1_000_000,  3_200_000],
        ];

        foreach ($expected as $tier => $band) {
            self::assertSame($band, $resolver->bandFor(CompanySize::LARGE, 'EN', $config, $tier), "tier {$tier}");
        }
    }

    public function testTheTierEightShareIsExactlyWhatTheRatioSays(): void
    {
        // The constraint the gate checks (tier-8 share within 3x of tier 1) is a statement
        // about these two numbers, so the arithmetic behind it is pinned on its own.
        $config = $this->config();
        $config->setEconomySponsorTierRatioBp([10000, 9000, 8000, 7000, 6000, 5000, 4000, 3300]);

        $resolver = $this->resolver($config);

        [$tier1Min] = $resolver->bandFor(CompanySize::LARGE, 'EN', $config, 1);
        [$tier8Min] = $resolver->bandFor(CompanySize::LARGE, 'EN', $config, 8);

        self::assertSame(25_000_000, $tier1Min);
        self::assertSame(8_250_000,  $tier8Min);
        self::assertSame(3300, intdiv($tier8Min * 10000, $tier1Min));
    }

    public function testANonMonotonicLadderIsAllowedRatherThanRejected(): void
    {
        // Reported by the gate, never forbidden by the setter: a division that pays better
        // than the one above it may be deliberate. The setter's job is eight integers.
        $config = $this->config();
        $config->setEconomySponsorTierRatioBp([10000, 2000, 9000, 1000, 1000, 1000, 1000, 1000]);

        $resolver = $this->resolver($config);

        self::assertSame([22_500_000, 72_000_000], $resolver->bandFor(CompanySize::LARGE, 'EN', $config, 3));
        self::assertSame([5_000_000,  16_000_000], $resolver->bandFor(CompanySize::LARGE, 'EN', $config, 2));
    }

    // ── The override wins ────────────────────────────────────────────────

    public function testAnExplicitRowIsUsedVerbatimAndTheRatioIsNotAppliedToIt(): void
    {
        // #1 of the three silent failures. The explicit table is already a resolved answer.
        $config = $this->config();
        $config->setEconomySponsorTierRatioBp([10000, 5000, 5000, 5000, 5000, 5000, 5000, 5000]);
        $config->setEconomySponsorBandsPerTierPence(['4' => $this->row(7_000_000, 9_000_000)]);

        $resolver = $this->resolver($config);

        self::assertSame([7_000_000, 9_000_000], $resolver->bandFor(CompanySize::LARGE, 'EN', $config, 4));
        // The neighbouring tier still rides the ratio, so the two tables coexist.
        self::assertSame([12_500_000, 40_000_000], $resolver->bandFor(CompanySize::LARGE, 'EN', $config, 5));
    }

    // ── Tier 0 is not tier 1 ─────────────────────────────────────────────

    public function testTheRatioIsNotReadForTierZero(): void
    {
        // #2. The published country-level `sponsorBandsPence` resolves at tier 0 and a
        // released client already reads it.
        $config = $this->config();
        $config->setEconomySponsorTierRatioBp([5000, 5000, 5000, 5000, 5000, 5000, 5000, 5000]);

        self::assertSame(self::FLAT['large'], $this->resolver($config)->bandFor(CompanySize::LARGE, 'EN', $config));
    }

    public function testATierAboveEightResolvesFlatRatherThanReadingPastTheTable(): void
    {
        $config = $this->config();
        $config->setEconomySponsorTierRatioBp([10000, 1000, 1000, 1000, 1000, 1000, 1000, 1000]);

        self::assertSame(self::FLAT['large'], $this->resolver($config)->bandFor(CompanySize::LARGE, 'EN', $config, 9));
    }

    // ── The ratio and the country factor are ONE chain ───────────────────

    public function testTheRatioAndTheCountryFactorTruncateOnceBetweenThem(): void
    {
        // #3. The flat columns are round numbers, which hides this entirely: 5,600,000 is a
        // multiple of 10,000, so no ratio can make it truncate at all. The fixture therefore
        // uses a band that is not round, and a ratio x factor pair where the two orders
        // genuinely disagree — by one penny, which is the whole point.
        $config = $this->config();
        $config->setSmallSponsorMin(1_234_567)->setSmallSponsorMax(7_654_321);
        $config->setEconomySponsorTierRatioBp([10000, 12345, 10000, 10000, 10000, 10000, 10000, 10000]);

        $resolver = $this->resolver($config, ['EN' => $this->profile('EN', 10000, true), 'ES' => $this->profile('ES', 6789)]);

        $lo       = 1_234_567;
        $onceOnly = intdiv($lo * 12345 * 6789, 10000 * 10000);
        $twice    = intdiv(intdiv($lo * 12345, 10000) * 6789, 10000);

        self::assertNotSame($twice, $onceOnly, 'the fixture no longer distinguishes the two orders');
        self::assertSame($onceOnly, $resolver->bandFor(CompanySize::SMALL, 'ES', $config, 2)[0]);
    }

    public function testACheapCountryScalesARatioedBandAsWell(): void
    {
        // THE BASE-YEAR RULE, restated for the ratio path: a ratio table is authored once and
        // every country reads it through its own factor.
        $config = $this->config();
        $config->setEconomySponsorTierRatioBp([10000, 10000, 10000, 10000, 10000, 10000, 10000, 2500]);

        $resolver = $this->resolver($config, ['EN' => $this->profile('EN', 10000, true), 'ES' => $this->profile('ES', 2000)]);

        self::assertSame([6_250_000, 20_000_000], $resolver->bandFor(CompanySize::LARGE, 'EN', $config, 8));
        self::assertSame([1_250_000, 4_000_000],  $resolver->bandFor(CompanySize::LARGE, 'ES', $config, 8));
    }

    // ── The roll spends the ratioed band ─────────────────────────────────

    public function testTheRolledPotUsesTheRatioForTheLeaguesOwnTier(): void
    {
        // The only point that actually spends money. A ratio authored, published and then
        // ignored here would be invisible until somebody compared two seasons of accounts.
        $config = $this->config();
        $config->setEconomySponsorTierRatioBp([10000, 10000, 10000, 10000, 10000, 10000, 10000, 1000]);
        // An equal band needs no roll, so the pot is exactly the resolved figure.
        $config->setLargeSponsorMin(50_000_000)->setLargeSponsorMax(50_000_000);

        $league  = new League('EN', 8, 'League 8');
        $sponsor = new Sponsor('Probe Co');
        $sponsor->setSize(CompanySize::LARGE);
        $league->addSponsor($sponsor);

        self::assertSame(5_000_000, $this->resolver($config)->rollLeagueSponsors($league, $config));
    }

    // ── Normalisation ────────────────────────────────────────────────────

    public function testTheRatioListIsAlwaysEightEntriesPaddedAtParity(): void
    {
        $config = (new GameConfig())->setEconomySponsorTierRatioBp([10000, 5000]);

        self::assertSame([10000, 5000, 10000, 10000, 10000, 10000, 10000, 10000], $config->getEconomySponsorTierRatioBp());
    }

    public function testANegativeRatioIsClampedToZeroAndZeroMeansNoSponsorship(): void
    {
        // 0 is a legitimate authored state here — unlike the ticket base, there is no
        // "unauthored" reading to protect: a tier whose ratio is 0 earns nothing, which is a
        // configuration an operator can see in the preview and the gate reports.
        $config = $this->config();
        $config->setEconomySponsorTierRatioBp([10000, -500, 0, 10000, 10000, 10000, 10000, 10000]);

        self::assertSame(0, $config->getEconomySponsorTierRatioBp()[1]);

        $resolver = $this->resolver($config);

        self::assertSame([0, 0], $resolver->bandFor(CompanySize::LARGE, 'EN', $config, 2));
        self::assertSame([0, 0], $resolver->bandFor(CompanySize::LARGE, 'EN', $config, 3));
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /** @return array<string, array{int, int}> */
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

        return new SponsorBandResolver($gameConfigRepo, new EconomyScaleResolver($gameConfigRepo, $starterRepo, $profileRepo, ReferenceSquadDoubles::poolConfigRepository(), ReferenceSquadDoubles::playerGeneration()));
    }

    private function profile(string $code, int $factorBp, bool $baseline = false): CountryProfile
    {
        $p = new CountryProfile($code, $code);
        $p->setEconomyFactorBp($factorBp);
        $p->setIsEconomyBaseline($baseline);

        return $p;
    }
}
