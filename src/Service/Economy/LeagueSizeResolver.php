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
 * ── THE SIZE IS DERIVED. `league.slots` IS NO LONGER READ ─────────────────────────────────
 *
 * **Owner ruling, 2026-11-19: "Retire slots."** Following the standing ruling that *"the
 * number of clubs in a country league tier is something that can change, as we alter the cache
 * and tune the system ahead of go live, so there should be no wiring around the amount of
 * clubs, the finances should be dynamic."*
 *
 * This docblock used to argue the opposite case, and the argument is kept here because the
 * cost it names is real and was accepted rather than refuted:
 *
 *   > *A derived divisor moves whenever somebody clicks Generate.* `League::$tvDeal` is a key
 *   > shipped builds read, and deriving it from a live NPC count means adding two clubs to a
 *   > tier silently re-prices that tier's TV money for every existing build, as a side effect
 *   > of an unrelated admin action.
 *
 * **What was given up, stated plainly:** a division's TV share is no longer a number anybody
 * authored. Regenerate a tier at a different club count and every per-club figure for that
 * tier moves with it, with no author and no migration. That is now the intended behaviour —
 * the owner's position is that a size nobody can change is worse, because the caches are being
 * rebuilt repeatedly during tuning and an authored divisor goes stale silently every time.
 *
 * **What makes the cost smaller than the docblock claimed.** Every path that publishes a TV
 * figure to a device already computes it at SERVE time through `derivedTvDealPence()` and never
 * reads the stored column — `SyncService:834`, `InitializeController:251`,
 * `LeagueService:306`, `LeagueImportExportService:66`. So the divisor was already dynamic on
 * the wire; what was authored was only the number it divided BY. The `league.tv_deal` column
 * survives as a cache that only admin surfaces read (`LeagueAdminController:127`,
 * `EconomyAdminController:457`, `WorldOverviewService:98`, `LeagueTierDefaultDiff:68`), and
 * `app:economy:wage-table`'s check 9 now watches it for exactly that staleness.
 *
 * **Why the drift it was protecting against was live anyway.** Measured off the 2026-11-19
 * world export (200 leagues, 3,175 clubs, 25 countries): Scotland authored `slots = 14` at
 * every tier against 12 NPC rows at tiers 1-7 and 11 at tier 8, so each Scottish club was
 * credited `pool ÷ 14` in a division of 12 — 85.7% of its due — and the shares a Scottish
 * division credited itself summed to £86,600,000.36 a season less than the pool it was
 * authored with. The authored number did not hold the divisor still; it held it wrong.
 *
 * ── THE DERIVATION, AND THE ONE ASSUMPTION IN IT ──────────────────────────────────────────
 *
 * NPC rows, **plus one at the country's deepest division**, which is where
 * `LeagueService::resolveNewLeague()` and `StarterPackService` put a new club. The `+1` is the
 * player's own place and it is UNCONDITIONAL: the player's club is in that division whether or
 * not the generator left a gap for it, so the table it fields is `rows + 1` either way. That is
 * also what the device assembles — the pack's `clubs` array plus the player's own club.
 *
 * ⚠️ `app:economy:wage-table`'s check 9 reports a deepest tier that does NOT hold one fewer row
 * than the tier above, because that is the one shape where the reservation cannot be
 * corroborated from the data. It is informational there, not a correction: nothing writes a
 * size any more.
 *
 * ── THE COLUMN IS STILL THERE, AND DELIBERATELY ───────────────────────────────────────────
 *
 * `league.slots` is not dropped. Nothing in this repository reads it; it is left in place
 * because dropping a column is irreversible on a box whose shipped clients are not all known,
 * and the owner's scope here is correctness now, not schema tidiness. Nothing writes it either
 * — the admin form shows the derived size read-only (`LeagueAdminController::quickEdit()`) and
 * the world import no longer restores it. It therefore holds whatever it last held, which the
 * wage-table check prints beside the live figure so a reader comparing an old export against a
 * running box can see at a glance which of the two numbers is the one in use.
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
     * NPC rows, plus one for the country's bottom division, which is where
     * `LeagueService::resolveNewLeague()` and `StarterPackService` put a new club. Returns 0
     * for a tier with NO rows at all — bottom tier included — which is honest: callers that
     * divide check for it rather than being handed a 1 that hides it.
     *
     * ⚠️ **`league.slots` is NOT consulted.** It used to win here, and the owner retired it on
     * 2026-11-19; the class docblock has the ruling and what it cost. The column still exists
     * and still holds a value — reading it again is the one change this file must not take.
     */
    public function sizeOf(League $league): int
    {
        $country = $league->getCountry();
        $npc     = $this->countsFor($country)[(string) $league->getId()] ?? 0;

        // ⚠️ AN EMPTY TIER IS 0 EVEN AT THE BOTTOM, AND THE GUARD IS LOAD-BEARING.
        //
        // Without it the unconditional bottom-tier `+1` turns a tier with no NPC rows into a
        // division of ONE and `derivedTvDealPence()` then publishes the entire TV pool as a
        // single club's share — exactly the "divide by a clamped 1" failure the 0 exists to
        // prevent, reached by a different route. It is not hypothetical:
        // `BootstrapCountryCommand` creates a country's leagues and then fills them, so every
        // country passes through this state, and `EconomyTvCountryScalingGuardTest` caught it
        // within minutes of `slots` stopping being read. A division with no clubs in it has no
        // size, player or no player.
        if ($npc === 0) {
            return 0;
        }

        return $npc + ($this->isBottomTier($league) ? 1 : 0);
    }

    /**
     * The per-club TV share implied by a league's authored pool, or null when there is none.
     *
     * ⚠️ **This is the figure every device gets**, computed at serve time by `SyncService:834`,
     * `InitializeController:251` and `LeagueService:306`. It therefore moves with the NPC rows
     * as of 2026-11-19, by the owner's ruling. The stored `tv_deal` column is now only a cache
     * that admin surfaces read, and it can be stale — see the class docblock.
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
