<?php

declare(strict_types=1);

namespace App\Tests\Service\Economy;

use App\Entity\GameConfig;
use App\Entity\League;
use App\Entity\LeagueSponsor;
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
 * Sponsorship has to scale by country, or a cheap country is only cheap on one side.
 *
 * THE DEFECT: the six sponsor band columns are flat pence, so a club in a country factored to
 * 20% earned the **same sponsorship as an English club while paying 20% wages**. Not "a cheaper
 * country" — a different game, with sponsorship five times more valuable relative to the wage
 * bill. Found by the step-8 threshold survey.
 *
 * THREE PROPERTIES, AND THE SECOND AND THIRD ARE THE ONES THAT WOULD BE EASY TO GET WRONG:
 *
 *  1. The band scales with the country factor.
 *  2. **It scales by COUNTRY ONLY, not country x tier.** A league's tier variation is already
 *     expressed by how many sponsors it has and which size bands they draw from, so applying
 *     the tier factor here as well would re-tier an already-tiered mechanic and quietly make a
 *     tier-1 pot enormous. Asserted by showing two tiers of the same country agree.
 *  3. **It is byte-identical at parity**, which is the shipped state — and unlike the wage
 *     model it is NOT gated on `economyWageModel`, so it starts applying the moment a factor is
 *     set. That is why the runbook demands a `WORLD_PACK_VERSION` bump on a factor change and
 *     not only on a model flip.
 */
class SponsorBandResolverTest extends TestCase
{
    // ── Inert at parity ──────────────────────────────────────────────────

    public function testEveryBandIsUnchangedAtParity(): void
    {
        // The shipped state. Every rolled sponsor value must come out exactly as it did before
        // the bands became country-scaled.
        $config   = $this->config();
        $resolver = $this->resolver($config, ['EN' => $this->profile('EN', isBaseline: true)]);

        self::assertSame([5_600_000, 8_200_000], $resolver->bandFor(CompanySize::SMALL, 'EN', $config));
        self::assertSame([8_200_000, 13_400_000], $resolver->bandFor(CompanySize::MEDIUM, 'EN', $config));
        self::assertSame([25_000_000, 80_000_000], $resolver->bandFor(CompanySize::LARGE, 'EN', $config));
    }

    public function testNoCountryMeansNoScaling(): void
    {
        // The signature allows an empty country for callers with no league in hand; it must
        // return the raw band rather than guessing.
        $config   = $this->config();
        $resolver = $this->resolver($config, ['EN' => $this->profile('EN', factorBp: 2000, isBaseline: true)]);

        self::assertSame([5_600_000, 8_200_000], $resolver->bandFor(CompanySize::SMALL, '', $config));
    }

    // ── It scales ────────────────────────────────────────────────────────

    public function testACheapCountryEarnsProportionatelyLessSponsorship(): void
    {
        // THE WHOLE POINT. England is the baseline; Spain stores a fifth of its factor, so a
        // Spanish sponsor pays a fifth — matching the fifth of the wages a Spanish club pays.
        $config   = $this->config();
        $resolver = $this->resolver($config, [
            'EN' => $this->profile('EN', factorBp: 10000, isBaseline: true),
            'ES' => $this->profile('ES', factorBp: 2000),
        ]);

        self::assertSame([1_120_000, 1_640_000], $resolver->bandFor(CompanySize::SMALL, 'ES', $config));
        self::assertSame([5_000_000, 16_000_000], $resolver->bandFor(CompanySize::LARGE, 'ES', $config));
    }

    public function testTheBaselineCountryIsParityWithItselfWhateverItStores(): void
    {
        // An operator may set England to 12000 thinking in absolutes; its own sponsorship must
        // not move as a side effect.
        $config   = $this->config();
        $resolver = $this->resolver($config, ['EN' => $this->profile('EN', factorBp: 12000, isBaseline: true)]);

        self::assertSame([5_600_000, 8_200_000], $resolver->bandFor(CompanySize::SMALL, 'EN', $config));
    }

    // ── Country only, not country x tier ─────────────────────────────────

    public function testTheTierFactorIsDeliberatelyNOTApplied(): void
    {
        // THE DECISION MOST LIKELY TO BE "CORRECTED" LATER. A league's tier variation is
        // already expressed by League::$sponsorCount (authored per tier) and the size mix;
        // multiplying by the tier factor too would double-count it. Two leagues of the same
        // country must therefore agree about a band whatever their tiers.
        $config = $this->config();
        $config->setEconomyTierFactorsBp([50000, 1000, 1000, 1000, 1000, 1000, 1000, 1000]);

        $resolver = $this->resolver($config, ['EN' => $this->profile('EN', isBaseline: true)]);

        $tier1 = $resolver->rollLeagueSponsors($this->leagueWith('EN', 1, CompanySize::LARGE), $config);
        $tier8 = $resolver->rollLeagueSponsors($this->leagueWith('EN', 8, CompanySize::LARGE), $config);

        // Both inside the same unscaled band — a 5x tier-1 factor must not inflate one of them.
        foreach ([$tier1, $tier8] as $pot) {
            self::assertGreaterThanOrEqual(25_000_000, $pot);
            self::assertLessThanOrEqual(80_000_000, $pot);
        }
    }

    // ── The roll ─────────────────────────────────────────────────────────

    public function testTheRollTakesTheCountryFromTheLeague(): void
    {
        // So a caller cannot forget it or get it wrong — the one way this could silently keep
        // the old behaviour.
        $config   = $this->config();
        $resolver = $this->resolver($config, [
            'EN' => $this->profile('EN', isBaseline: true),
            'ES' => $this->profile('ES', factorBp: 1000),
        ]);

        $pot = $resolver->rollLeagueSponsors($this->leagueWith('ES', 1, CompanySize::LARGE), $config);

        self::assertGreaterThanOrEqual(2_500_000, $pot);
        self::assertLessThanOrEqual(8_000_000, $pot);
    }

    public function testTheRolledValueIsWrittenBackOntoEachSponsor(): void
    {
        // The loop mutates managed entities and leaves the flush to the caller, which is what
        // both original copies did.
        $config   = $this->config();
        $resolver = $this->resolver($config, []);
        $league   = $this->leagueWith('EN', 1, CompanySize::SMALL, CompanySize::LARGE);

        $total = $resolver->rollLeagueSponsors($league, $config);

        $summed = 0;
        foreach ($league->getLeagueSponsors() as $ls) {
            self::assertGreaterThan(0, $ls->getRolledValue(), 'every sponsor must be priced');
            $summed += $ls->getRolledValue();
        }

        self::assertSame($total, $summed, 'the returned pot must be the sum of what was written');
    }

    public function testAnEmptyLeagueRollsToZero(): void
    {
        $resolver = $this->resolver($this->config(), []);

        self::assertSame(0, $resolver->rollLeagueSponsors(new League('EN', 1, 'League 1')));
    }

    public function testAnEqualBandNeedsNoRoll(): void
    {
        // `random_int()` throws on an inverted range and needs no call for an equal pair —
        // behaviour preserved from both originals.
        $config = $this->config();
        $config->setLargeSponsorMin(42_000_000);
        $config->setLargeSponsorMax(42_000_000);

        $resolver = $this->resolver($config, []);

        self::assertSame(
            42_000_000,
            $resolver->rollLeagueSponsors($this->leagueWith('EN', 1, CompanySize::LARGE), $config),
        );
    }

    public function testATransposedBandIsNormalisedRatherThanThrowing(): void
    {
        // The two halves are set independently in the admin, so a swapped pair is a real state
        // of the row — and `random_int(max, min)` would throw rather than degrade.
        $config = $this->config();
        $config->setLargeSponsorMin(80_000_000);
        $config->setLargeSponsorMax(25_000_000);

        $resolver = $this->resolver($config, []);

        self::assertSame([25_000_000, 80_000_000], $resolver->bandFor(CompanySize::LARGE, 'EN', $config));
        self::assertGreaterThanOrEqual(
            25_000_000,
            $resolver->rollLeagueSponsors($this->leagueWith('EN', 1, CompanySize::LARGE), $config),
        );
    }

    // ── The published shape ──────────────────────────────────────────────

    public function testBandsForCarriesEverySizeKeyedByItsValue(): void
    {
        $config = $this->config();
        $bands  = $this->resolver($config, [])->bandsFor('EN', $config);

        self::assertSame(['small', 'medium', 'large'], array_keys($bands));

        foreach ($bands as $size => $band) {
            self::assertArrayHasKey('min', $band, $size);
            self::assertArrayHasKey('max', $band, $size);
            self::assertLessThanOrEqual($band['max'], $band['min'], $size);
        }
    }

    // ───────────────────────────── plumbing ─────────────────────────────

    /** The live England bands, as exported 2026-11-12 (pence, per season). */
    private function config(): GameConfig
    {
        $config = new GameConfig();
        $config->setSmallSponsorMin(5_600_000)->setSmallSponsorMax(8_200_000);
        $config->setMediumSponsorMin(8_200_000)->setMediumSponsorMax(13_400_000);
        $config->setLargeSponsorMin(25_000_000)->setLargeSponsorMax(80_000_000);

        return $config;
    }

    private function leagueWith(string $country, int $tier, CompanySize ...$sizes): League
    {
        $league = new League($country, $tier, "League {$tier}");

        foreach ($sizes as $i => $size) {
            $sponsor = new Sponsor('Probe Co ' . $i);
            $sponsor->setSize($size);
            $league->addSponsor($sponsor);
        }

        return $league;
    }

    /** @param array<string, CountryProfile> $profiles */
    private function resolver(GameConfig $config, array $profiles): SponsorBandResolver
    {
        $gameConfigRepo = $this->createStub(GameConfigRepository::class);
        $gameConfigRepo->method('getConfig')->willReturn($config);

        $starterRepo = $this->createStub(StarterConfigRepository::class);
        $starterRepo->method('getConfig')->willReturn(new StarterConfig());

        $profileRepo = $this->createStub(CountryProfileRepository::class);
        $profileRepo->method('findByCode')->willReturnCallback(
            static fn (string $code) => $profiles[strtoupper(trim($code))] ?? null,
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

        return new SponsorBandResolver(
            $gameConfigRepo,
            new EconomyScaleResolver($gameConfigRepo, $starterRepo, $profileRepo),
        );
    }

    private function profile(string $code, int $factorBp = 10000, bool $isBaseline = false): CountryProfile
    {
        $profile = new CountryProfile($code, $code);
        $profile->setEconomyFactorBp($factorBp);
        $profile->setIsEconomyBaseline($isBaseline);

        return $profile;
    }
}
