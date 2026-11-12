<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\GameConfig;
use App\Entity\League;
use App\Enum\CompanySize;
use App\Repository\GameConfigRepository;

/**
 * What a league sponsor pays, scaled for the country the league is in.
 *
 * ── WHAT THIS REPLACED, AND WHY IT IS THE FIFTH TIME ──────────────────────────────────────
 *
 * `LeagueService::rollLeagueSponsors()` and `WorldInitializationService::rollLeagueSponsors()`
 * held **byte-identical** copies of the same loop. Both are still public — one is called by
 * `WorldPackTierAssemblyService` — but both now delegate here, so there is one implementation.
 *
 * That is the fifth duplication this overhaul has collapsed, after three copies of the wage
 * tier-walk and three of the ability-band lookup. The pattern is consistent enough to be worth
 * naming: **money arithmetic in this codebase gets copied to the second caller rather than
 * extracted**, and every copy is a place the two can disagree about a figure that is then
 * stored in a cached pack and read for the life of a save.
 *
 * ── THE DEFECT: SPONSORSHIP DID NOT SCALE, WAGES DID ──────────────────────────────────────
 *
 * The six band columns are flat pence. A club in a country factored to 20% therefore earned the
 * **same sponsorship as an English club while paying 20% wages** — not "a cheaper country", a
 * different game, with sponsorship five times more valuable relative to the wage bill. Found by
 * the step-8 threshold survey.
 *
 * ── COUNTRY FACTOR ONLY, NOT COUNTRY x TIER ───────────────────────────────────────────────
 *
 * Deliberately narrower than the wage chain, which uses `combinedFactorBp`. A league's tier
 * variation is **already expressed** in this mechanic by two things the tier factor would
 * double-count: how many sponsors a league has (`League::$sponsorCount`, authored per tier) and
 * which `CompanySize` bands they draw from. Applying the tier factor on top would re-tier a
 * mechanic that is already tiered, and quietly make a tier-1 pot enormous.
 *
 * So the fix is exactly as wide as the defect: the country dimension, which nothing else
 * expressed.
 *
 * ── INERT AT PARITY ───────────────────────────────────────────────────────────────────────
 *
 * `countryFactorBp` is 10000 for every country in the shipped state, so every rolled value is
 * byte-identical to what it was. Unlike the wage model this is NOT gated on
 * `economyWageModel` — a sponsor band has nothing to do with how a player is priced — so it
 * starts applying the moment a country factor is set. That is why the runbook requires a
 * `WORLD_PACK_VERSION` bump when a factor changes, not only when the model flips:
 * `sponsorPot` is part of the cached league block.
 */
final class SponsorBandResolver
{
    private const BP = 10_000;

    public function __construct(
        private readonly GameConfigRepository $gameConfigRepository,
        private readonly EconomyScaleResolver $scaleResolver,
    ) {}

    /**
     * The per-season pence band for one company size, scaled for a country.
     *
     * Normalised on read: the two halves are set independently in the admin, so a transposed
     * pair is a legitimate state of the row — the same reasoning
     * `ScoutWageCalculator::weeklyWageFor()` documents.
     *
     * @return array{int, int} [min, max]
     */
    public function bandFor(CompanySize $size, string $country = '', ?GameConfig $config = null): array
    {
        $config = $config ?? $this->gameConfigRepository->getConfig();

        [$min, $max] = match ($size) {
            CompanySize::SMALL  => [$config->getSmallSponsorMin(),  $config->getSmallSponsorMax()],
            CompanySize::MEDIUM => [$config->getMediumSponsorMin(), $config->getMediumSponsorMax()],
            CompanySize::LARGE  => [$config->getLargeSponsorMin(),  $config->getLargeSponsorMax()],
        };

        $lo = min($min, $max);
        $hi = max($min, $max);

        if ($country === '') {
            return [$lo, $hi];
        }

        $factorBp = $this->scaleResolver->countryFactorBp($country);

        return [intdiv($lo * $factorBp, self::BP), intdiv($hi * $factorBp, self::BP)];
    }

    /**
     * Every band for a country, keyed by company-size value. The published shape.
     *
     * @return array<string, array{min: int, max: int}>
     */
    public function bandsFor(string $country = '', ?GameConfig $config = null): array
    {
        $out = [];

        foreach (CompanySize::cases() as $size) {
            [$min, $max]       = $this->bandFor($size, $country, $config);
            $out[$size->value] = ['min' => $min, 'max' => $max];
        }

        return $out;
    }

    /**
     * Re-roll every sponsor on a league and return the total pot.
     *
     * THE ONE IMPLEMENTATION. Mutates each `LeagueSponsor`'s `rolledValue` and leaves the flush
     * to the caller, which is what both original copies did — `LeagueService`'s own docblock on
     * the replay path notes that these are managed entities whose re-rolls it deliberately does
     * not persist itself.
     *
     * The country comes from the league, so a caller cannot get it wrong or forget it.
     */
    public function rollLeagueSponsors(League $league, ?GameConfig $config = null): int
    {
        $config = $config ?? $this->gameConfigRepository->getConfig();
        $total  = 0;

        foreach ($league->getLeagueSponsors() as $ls) {
            [$min, $max] = $this->bandFor($ls->getSponsor()->getSize(), $league->getCountry(), $config);

            // `$max > $min` rather than `>=`, preserved from both originals: random_int() throws
            // on an inverted range, and an equal pair needs no roll.
            $value = $max > $min ? random_int($min, $max) : $min;

            $ls->setRolledValue($value);
            $total += $value;
        }

        return $total;
    }
}
