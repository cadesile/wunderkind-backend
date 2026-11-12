<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\League;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;

/**
 * How many clubs play in a league — the single divisor for the TV pool.
 *
 * ── A `League` ROW IS A TEMPLATE, NOT A COMPETITION ────────────────────────────────────────
 *
 * This is the fact the whole class exists for, and it is not obvious from the schema. Every
 * save in a country/tier points at the SAME `League` row: on 2026-11-12 England tier 8 had
 * **147 real, non-spoof player clubs** with `current_league_id` set to it (tier 7 had 9, tier 6
 * had 5). The eighteen-club table a player sees is assembled on their device from the world
 * pack — NPC clubs plus their own club — and exists nowhere on the server.
 *
 * So **no count over `club` rows means anything here**, and anything that looks like "how many
 * clubs are in this league" has to be answered deliberately rather than queried. Counting
 * player clubs would give England tier 8 a divisor of 148.
 *
 * ── WHY THE SIZE IS STORED AND NOT DERIVED ────────────────────────────────────────────────
 *
 * The obvious derivation — NPC rows, plus one for the bottom tier where new clubs are placed —
 * is right for 9 of the 15 bootstrapped countries and wrong for 6. NPC counts per tier are an
 * artefact of how a country happened to be bootstrapped (`BootstrapCountryCommand`'s
 * `--clubs-per-tier` defaults to 8), not a declared size. Measured on dev, 2026-11-12:
 *
 *   EN, DE, IT             18 x7, 17   -> rule gives a clean 18
 *   AR, BR, ES, FR, NL, PT 16 x7, 15   -> rule gives a clean 16
 *   CA, JP, NG, PL, US     8 x8        -> rule gives 9 at the bottom, 8 everywhere else
 *   SC                     3 x8        -> rule gives 4 at the bottom, 3 everywhere else
 *
 * The bottom tier being one short in the first two groups is exactly the player's slot, which
 * is the observation the rule is built on — and the uniform-count countries are simply ones
 * nobody has finished bootstrapping at a real size.
 *
 * The fatal objection is not the off-by-one, though: **a derived divisor moves whenever
 * somebody clicks Generate.** `League::$tvDeal` is a key shipped builds read, and deriving it
 * from a live NPC count means adding two clubs to a tier silently re-prices that tier's TV money
 * for every existing build, as a side effect of an unrelated admin action. That is a
 * backwards-compatibility break with no author.
 *
 * So `league.slots` is authored, **0 means "derive"** (the repo's own zero-means-unset idiom,
 * like `starter_config.club_funding_minimum_pence` and `max_enrichment_requests_per_user`), and
 * the derivation is kept as the fallback. An operator who prefers the pure rule simply never
 * sets `slots` and gets exactly the proposed behaviour.
 */
final class LeagueSizeResolver
{
    public function __construct(
        private readonly NpcClubRepository $npcClubRepository,
        private readonly LeagueRepository  $leagueRepository,
    ) {}

    /** @var array<string, array<string, int>> country => (league id => npc count) */
    private array $countCache = [];

    /** @var array<string, int|null> country => its deepest existing tier */
    private array $bottomTierCache = [];

    /**
     * The number of clubs this league's table holds.
     *
     * Authored `slots` wins; otherwise NPC rows plus one for the country's bottom division,
     * which is where `LeagueService::resolveNewLeague()` and `StarterPackService` put a new
     * club. Returns 0 for an empty non-bottom tier, which is honest — callers that divide
     * check for it rather than being handed a 1 that hides it.
     */
    public function sizeOf(League $league): int
    {
        $slots = $league->getSlots();
        if ($slots > 0) {
            return $slots;
        }

        $country = $league->getCountry();
        $npc     = $this->countsFor($country)[(string) $league->getId()] ?? 0;

        return $npc + ($this->isBottomTier($league) ? 1 : 0);
    }

    /**
     * The per-club TV share implied by a league's authored pool, or null when there is none.
     *
     * NULL IS THE INERT ANSWER AND THE COMMON ONE. A league with no `tvPoolPence` keeps the
     * `tvDeal` it was authored with, untouched — which is what makes shipping this a provable
     * no-op. A league with a pool but no resolvable size (an empty tier, mid-bootstrap) also
     * returns null rather than dividing by a clamped 1 and publishing the whole pool as one
     * club's share.
     */
    public function derivedTvDealPence(League $league): ?int
    {
        $pool = $league->getTvPoolPence();
        if ($pool === null) {
            return null;
        }

        $size = $this->sizeOf($league);

        return $size > 0 ? intdiv($pool, $size) : null;
    }

    /**
     * Writes the derived per-club share onto `tvDeal`, if there is one to write.
     *
     * The single place every write path goes through, so `tvDeal` and `tvPoolPence` cannot
     * disagree about arithmetic. Deliberately does nothing when the pool is null: `tvDeal`
     * remains the authored per-club figure released builds have always read.
     */
    public function syncTvDeal(League $league): void
    {
        $derived = $this->derivedTvDealPence($league);

        if ($derived !== null) {
            $league->setTvDeal($derived);
        }
    }

    /**
     * Invalidate the memoised counts.
     *
     * Needed because the write paths generate clubs and then resolve sizes inside one request —
     * `BootstrapCountryCommand` creates leagues, fills them, and only then has a real size to
     * author. Without this the resolver would answer from counts taken before the clubs existed.
     */
    public function forget(?string $country = null): void
    {
        if ($country === null) {
            $this->countCache      = [];
            $this->bottomTierCache = [];

            return;
        }

        $key = strtoupper(trim($country));
        unset($this->countCache[$key], $this->bottomTierCache[$key]);
    }

    /** @return array<string, int> */
    private function countsFor(string $country): array
    {
        $key = strtoupper(trim($country));

        return $this->countCache[$key] ??= $this->npcClubRepository->countsByLeagueForCountry($key);
    }

    /**
     * Whether this is the deepest league the country actually has.
     *
     * The deepest EXISTING row, not `country_profile.league_tier_count` — a new club is placed
     * by `LeagueRepository::findLowestTierForCountry()`, so "where the player's club lands" and
     * "which league gets the extra slot" have to be the same question. A declared depth of 8
     * with only 4 leagues built would otherwise give the extra slot to a tier that does not
     * exist.
     */
    private function isBottomTier(League $league): bool
    {
        $key = strtoupper(trim($league->getCountry()));

        if (!array_key_exists($key, $this->bottomTierCache)) {
            $this->bottomTierCache[$key] = $this->leagueRepository
                ->findLowestTierForCountry($league->getCountry())?->getTier();
        }

        return $this->bottomTierCache[$key] === $league->getTier();
    }
}
