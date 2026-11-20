<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\GameConfig;
use App\Entity\League;
use App\Entity\Agent;
use App\Repository\AgentRepository;
use App\Repository\GameConfigRepository;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\StarterConfigRepository;
use App\Service\ClubInitializationService;

class WorldInitializationService
{
    /**
     * Shape version of the world-pack payload. Bump this whenever **anything** in the
     * payload changes shape — WorldPackCacheService treats any cached row stamped with a
     * different version as a miss and rebuilds it. Not just the snapshot builders: the
     * league-level block in buildLeagueSnapshot() counts too, which is how version 3 came
     * to be needed.
     *
     * 1 — first version to guarantee a generated `personality` matrix on players,
     *     staff and scouts. Everything cached before this may carry all-10s
     *     defaults, and anything cached before 2026-08-23 has no `personality`
     *     key on staff/scout at all.
     *
     * ⚠️ Run `app:backfill-personalities` BEFORE bumping this, or the rebuilt
     * packs just re-serialise the same default matrices.
     *
     * 2 — NPC club `staff[]` may now include `director_of_football`/
     *     `facility_manager` entries, and each club snapshot gains a new
     *     `scouts[]` array (see `StarterConfig::$npcSquadConfig`'s
     *     directorOfFootballCount/facilityManagerCount/scoutCount). Packs
     *     cached under version 1 lack these entirely and are treated as a
     *     miss on next read.
     *
     * 3 — the league block carries `trophy` (the 16-bit trophy sprite's own
     *     TrophyConfig) instead of the retired `trophyImage`/`trophyColour`
     *     asset-slug pair. The code changed with the trophy-sprite work
     *     (`Version20261018202041`, 2026-10-18) but this constant was **not**
     *     bumped with it, so every pack cached before that date kept serving
     *     the retired shape — 84 of 85 rows on the production restore, and
     *     forever, since the cache has no TTL. Exactly the failure this
     *     constant exists to prevent, and the second time it has happened
     *     (see version 1's note about all-10s personality matrices).
     *
     * 4 — every NPC club snapshot gains a `land` block: the town it plays in,
     *     that town's region, whether it is a capital, the number of the land
     *     plot its ground stands on, and what that ground costs in pence.
     *     `region`, `citySize`, `populationSize` and `isCapital` are untouched
     *     on the club, so the addition is additive — but a pack cached under
     *     version 3 has no `land` key at all, and without this bump it would
     *     keep being served forever, the cache having no TTL. That is the
     *     third time, and the first one caught before it shipped rather than
     *     after: npc_club.land_plot_id and the snapshot change landed in the
     *     same commit as this line.
     *
     *     `land.plotNumber` is null until `app:npc-clubs:seat-plots` has run
     *     for the country (generation seats new clubs itself), so warm AFTER
     *     seating or the rebuilt packs carry nulls.
     *
     * ⚠️ **BUMP THIS WHEN `economyWageModel` IS FLIPPED, OR WHEN A COUNTRY FACTOR CHANGES.**
     * Those change pack CONTENT without changing pack SHAPE, which is the one case this
     * constant does not catch on its own — a pack holds the wages its players were generated
     * with, and the cache has no TTL, so every already-cached country would serve pre-flip
     * wages forever. That is the same failure as versions 1, 3 and 4 above, in the one guise
     * where nothing about the payload looks different.
     *
     * Not bumped by the economy work itself (2026-11-12): it ships with the wage model on
     * `legacy` and every country factor at parity, so nothing generated changed and
     * invalidating 120 packs would have bought nothing. The bump belongs to the operator pass
     * that turns it on — see `docs/deploy/economy-rollout.md` and
     * `app:economy:wage-table`, which is the gate in front of that decision.
     *
     * ⚠️ Bumping this makes every cached row a miss, so the next client request per
     * country/tier pays the rebuild (~2s for an 8-club tier, ~3-4s for 18) on its
     * critical path. To pre-empt that, run plain `app:worldpack:warm <CODE>` per
     * country — **not** `--force`: a stale-versioned row is already a miss that gets
     * rebuilt through the atomic replace path, while `--force` in all-tiers mode deletes
     * the country's entries up front and leaves it with none until the run finishes.
     * Warming is a manual step, and the admin dashboard's `stalePacks` count is the
     * signal for what is still outstanding.
     *
     * 5 — the payload now carries its own `packVersion`, so a DEVICE can tell which
     *     generation of the world it is holding. Until now the version existed only as a
     *     column on the cache row: the server knew, the client did not, and a pack built
     *     before a calibration was indistinguishable from one built after it. On 2026-11-15
     *     that difference was live and large — 60 packs carried turnover wages, re-authored
     *     TV and parachutes, while 140 still held the pre-calibration economy, and nothing
     *     in the payload said which you had. A player reporting "my TV money looks wrong"
     *     could not be placed on either side of it.
     *
     *     Adding a key IS a shape change, which is why this is a bump rather than a quiet
     *     addition — the rule at the top of this docblock applies to the service's own
     *     changes too.
     *
     * 6 — the sponsor tier-1 base and the per-tier ratio tables were AUTHORED on 2026-11-15,
     *     which is the content-not-shape case the warning above describes, in its second
     *     guise. `sponsorPot` is part of the cached league block: it is rolled at pack-build
     *     time from whatever bands were resolved then, so every pack cached before the
     *     authoring holds pots drawn from the old flat band and would serve them forever.
     *     Nothing about those payloads looks different.
     *
     *     The figures: a tier-1 base at 13.041x the previous flat columns, with
     *     `economySponsorTierRatioBp` `[10000, 3475, 2540, 1658, 1658, 827, 340, 96]`. The
     *     defect it closes is that one flat band served every division, so a tier-8 league
     *     pot equalled tier 1's — measured in the app, club plus league sponsorship came to
     *     175% of tier-8 authored turnover against 1.2% at tier 1. It now lands at 22% at
     *     both ends.
     *
     *     ⚠️ The ticket ladder authored in the same pass (`economyTicketPriceTier1Pence`
     *     8000 plus `economyTicketPriceTierRatioBp`) did NOT require this bump on its own:
     *     the ticket price is published-only and is not part of any cached pack. The
     *     sponsor half is what makes the bump mandatory, and conflating the two would
     *     teach the next reader the wrong rule.
     *
     * 7 — `prizeMoney` and `leaguePositionPot` are now RESOLVED through
     *     `LeaguePayoutResolver` rather than read off the league column, and the same release
     *     nulls the column on every league still holding a cascaded tier default. Another
     *     content-not-shape bump: the two keys and their types are untouched, but both figures
     *     are in the cached league block above, so a pack cached before this would keep serving
     *     the inverted ladder — tier-8 champions on £5,000,000 against £164,852 of authored
     *     turnover — with nothing about the payload looking different.
     *
     *     The ladder: tier-1 bases unchanged at £100,000,000 for both, with
     *     `economyPrizeTierRatioPpm` and `economyPositionPotTierRatioPpm` both
     *     `[1000000, 309000, 91500, 51200, 18200, 6020, 1500, 424]` — parts per million, not
     *     basis points, because tier 8 is 4.24 bp and one bp step there would be 23.6% of the
     *     value. That puts both figures at a flat 0.2573x of each tier's own authored turnover,
     *     which is tier 1's existing ratio, and takes the EN tier-8 division's total payout
     *     from 5.42x its combined authored turnover to 0.12x.
     *
     *     ⚠️ The bump goes with THE CODE, not with the authoring pass. The resolver reads
     *     `GameConfig` at pack-build time, so the figures move the moment the ratios are
     *     authored — and the 2026-11-13 `tv_deal` incident is what happens when that is left
     *     to the data step: 60 of 200 packs sat on the current version and 25x out of date.
     *
     *   - **8 (2026-11-17)** — staff pay gained a tier dimension, and staff ABILITY gained a
     *     tier band. A tier-8 club was paying ~£1,000/wk in staff against ~£165/wk for its
     *     whole squad, because the role bands are flat absolutes while players scale with an
     *     ability that is per-tier. `buildStaffSnapshot()` writes `weeklySalary` into every
     *     pack, so this is a CONTENT change at an unchanged shape — the case this constant
     *     exists for. Every WorldPackSet became unclaimable on the bump;
     *     `app:worldpack:maintain` restocks live countries without an operator.
     *
     *   - **9 (2026-11-18)** — scout EXPERIENCE gained the tier band that version 8 gave staff
     *     ability. Scouts were missed then because a Scout is its own entity and never passes
     *     through `staffWeeklyPence()`: a tier-8 club still got a scout rated 33 beside
     *     coaches rated 2-7, and the app — which splits a club's wage reservation in
     *     proportion to ability — paid that one scout £2,230/wk of a £4,308 staff bill.
     *     `buildScoutSnapshot()` carries `experience` and the `tier` derived from it, so this
     *     is again a CONTENT change at an unchanged shape.
     *
     *   - **10 (2026-11-18)** — the re-derived economy (resolution R2) is AUTHORED:
     *     `Version20261118120000` rewrites the baseline turnover ladder, both payout ratio
     *     tables and their tier-1 bases, and band 3 of `npcFacilityLevelRanges`. A pack bakes
     *     facilities, wages, `tvDeal` and both payout figures, so every one of those moves
     *     under an unchanged shape — the case this constant exists for, and the fourth content
     *     bump in a row.
     *
     *     Turnover goes to `[£331m, £114m, £34.6m, £20.7m, £7.32m, £2.69m, £686k, £215k]` for
     *     the BASELINE country (so every country moves, all being at parity today), both
     *     ladders to `[1000000, 344411, 104532, 62538, 22115, 8127, 2073, 650]` on a tier-1
     *     base of £85,166,300, and band 3 to a bottom-division ground of eight facilities.
     *
     *     ⚠️ THE BUMP IS NOT ENOUGH ON ITS OWN HERE. Existing NPC clubs keep the facilities
     *     they were generated with, so the band-3 change reaches nothing until the bottom two
     *     tiers are REGENERATED; the bump and `app:worldpack:maintain` only restock what is
     *     generated after that. Wages and the payout figures do move on the bump alone, since
     *     the resolvers read `GameConfig` at pack-build time.
     *
     *   - **11 (2026-11-18)** — ALL MONEY LEAVES THE PACK. `tvDeal`, `sponsorPot`,
     *     `prizeMoney` and `leaguePositionPot` off the league; `startingBalance` and
     *     `land.valuePence` off the club; `contractValue` and `agent.commissionRate` off the
     *     player; `weeklySalary` off the staff member. The scout block already shipped no
     *     wage and is the pattern the rest now follows. `leaguePositionDecreasePercent` stays
     *     — it is a rule, not an amount.
     *
     *     ⚠️ **THIS IS THE LAST BUMP MONEY WILL EVER CAUSE, and that is the point of it.**
     *     Every bump above invalidates every cached pack in every country, and three of the
     *     five before this one (v6 sponsor bands, v7 payout ladders, v8 staff pay) were
     *     PURELY money — a figure was re-authored, so a payload full of squads had to be
     *     rebuilt. This one cost 208 of 256 tier payloads to author a turnover table. After
     *     this change the pack is a CENSUS (`WorldPackSnapshotBuilder`'s class docblock):
     *     teams, players, staff, abilities, facilities, personalities. Re-authoring any
     *     economy figure now reaches a new save with NO bump and NO regeneration, because
     *     every monetary quantity is resolved on the device from live config.
     *
     *     So a future money change does not belong here. If you are reaching for a bump
     *     because a wage, balance, payout or price moved, the answer is that nothing needs
     *     bumping — and if a pack turns out to still carry that figure,
     *     `WorldPackCarriesNoMoneyTest` is the thing that should have caught it.
     *
     *   - **12 (2026-11-20)** — `scouts[].ability`, a new CENSUS key on the scout block.
     *     `buildScoutSnapshot()` gains the scout's single 1-100 ability, derived on the backend
     *     from `experience` + `judgements` by `ScoutAbilityCalculator` and stored on
     *     `scout.ability`.
     *
     *     ⚠️ **THIS IS NOT THE RULE ABOVE BEING BROKEN ONE COMMIT LATER, AND THE DISTINCTION IS
     *     THE WHOLE POINT OF v11.** v11 says money will never cause another bump. `ability` is
     *     not money — it is what a scout IS, the same class of content as the player and staff
     *     abilities the pack has always carried, and v9 (scout experience banded) and v10
     *     (ability/facility content) were both bumps of exactly this kind. The census rule bans
     *     amounts from the pack; it does not freeze the census.
     *
     *     Why it is in the pack at all, rather than derived on device: `experience` and
     *     `judgements` are both already here, so the app COULD compute it and no payload would
     *     change — and that is precisely what makes it dangerous. It would be a second source
     *     for one quantity with nothing saying which is authoritative, the governing defect the
     *     economy workstream exists to remove. Money resolves on device because the device holds
     *     the config that prices it; an ability cannot, because it is per-row data, not a dial.
     *     So the backend owns it and the app reads it.
     *
     *     Nothing else moves on this bump: no existing key changes shape, type or meaning.
     *     `scouts[].tier` stays derived from `experience` deliberately — re-pointing it at
     *     `ability` would be a changed value under an unchanged key, i.e. a break.
     *
     *   - **13 (2026-11-20, A27)** — `scouts[].judgements` gains two keys, `youth` and `senior`.
     *     Owner ruling, asked whether scouting accuracy should vary by the player's age:
     *     **"Yes, it should."**
     *
     *     ⚠️ **THIS BUMP IS NOT OPTIONAL, AND IT IS NOT COSMETIC.** The app's `ageGateFor()`
     *     has read `judgements.youth` / `judgements.senior` since it was written, and
     *     `ScoutGenerationService::build()` has never written either — so it resolved to 0 for
     *     every scout that has ever existed, making every scout's displayed accuracy 70% of its
     *     designed value and leaving the real noise band permanently untightened. A pack cached
     *     at v12 carries the five-key judgements and would keep that feature dead on any device
     *     it reached, so the bump is what actually delivers the fix.
     *
     *     Census, not money, and the same kind of content as the v12 bump above: two more
     *     per-scout accuracy ratings on the 0-100 scale the other five already use. No amount
     *     enters the pack. The pack-is-a-census rule bans prices, not the census.
     *
     *     ⚠️ **`scouts[].ability` MOVES ON THIS BUMP, UNDER AN UNCHANGED KEY — and that is
     *     sanctioned rather than a break.** `ScoutAbilityCalculator` averages judgements over the
     *     keys PRESENT, so five keys to seven changes the judgement mean and therefore the
     *     ability of every scout in the world. The figure's definition and type are unchanged;
     *     its inputs grew. `Version20261120120000` writes the keys and re-derives the column in
     *     one migration, and `app:backfill-scout-abilities --force` is run behind it, so no
     *     cached pack and no stored row ever disagrees with the formula. Measured on dev: the
     *     ability distribution over 5,278 scouts moved from 11-48 (mean 29.13) to the figures
     *     recorded in `wunderkind-docs/economy-loose-ends/a27-scout-age-gate-report.md`.
     *
     *     Nothing else moves: no other key changes shape, type or meaning, and no app change was
     *     needed — `ScoutJudgements` already declared all seven keys and the UI already had
     *     labels for the two new rows.
     *
     * v14 (2026-11-20, A25) — **staff and scout ABILITY move, under unchanged keys, because the
     *     band they are drawn from changed source.** A club's staff were drawn from the squad's
     *     PLAYER band (potential) or, with none passed, from two flat global integers; they are
     *     now drawn from `pool_config.staff_ability_ranges`, authored per country and per tier
     *     and defined against player `current_ability`. `staff[].coachingAbility`,
     *     `staff[].scoutingRange`, `staff[].weeklySalary` (derived from ability by
     *     `WageModelResolver::staffWeeklyPence()`) and `scouts[].experience`/`ability` therefore
     *     all take different values for the same club than a v13 pack holds.
     *
     *     Census, not money in the pack's sense — no amount is added, and `staff.weekly_salary`
     *     was already written here for the backend's non-pack consumers. No key changes shape,
     *     type or meaning, and no app change is needed: the app reads the same fields it always
     *     did.
     *
     *     ⚠️ **THE BUMP IS WHAT DELIVERS THE FIX.** A cached v13 pack holds staff generated
     *     from the player band, and nothing re-derives a staff member the way
     *     `app:backfill-scout-abilities` re-derives a scout's `ability` — a generated staff
     *     member exists nowhere but in the pack that holds it. So a pack that is not rebuilt
     *     keeps the old, wrong band indefinitely. See the backfill section of
     *     `wunderkind-docs/economy-loose-ends/a25-ability-bands-report.md`: the `staff` and
     *     `scout` TABLES need no backfill at all, because every persisted row in them is a
     *     shared-pool row and the untiered band is unchanged.
     */
    public const WORLD_PACK_VERSION = 14;

    /** Ability range by tier — indexes 1-8 */
    public const ABILITY_RANGES = [
        1 => ['min' => 75, 'max' => 95],
        2 => ['min' => 65, 'max' => 85],
        3 => ['min' => 55, 'max' => 75],
        4 => ['min' => 45, 'max' => 65],
        5 => ['min' => 35, 'max' => 55],
        6 => ['min' => 25, 'max' => 45],
        7 => ['min' => 15,  'max' => 35],
        8 => ['min' => 10,  'max' => 25],
    ];

    public function __construct(
        private readonly LeagueRepository         $leagueRepository,
        private readonly NpcClubRepository        $npcClubRepository,
        private readonly AgentRepository          $agentRepository,
        private readonly StarterConfigRepository  $starterConfigRepository,
        private readonly GameConfigRepository     $gameConfigRepository,
        private readonly PoolConfigRepository     $poolConfigRepository,
        private readonly FixtureGenerationService $fixtureGenerationService,
        private readonly WorldPackSnapshotBuilder $snapshotBuilder,
        private readonly WorldPackClubGenerationService $clubGenerator,
        // NO LeaguePayoutResolver. It was injected for `prizeMoney`/`leaguePositionPot`, which
        // left the pack at v11; `GET /api/initialize/leagues` resolves both live and injects it
        // itself. An unused money resolver on a pack builder is an invitation to re-add a key.
        private readonly \App\Service\Economy\SponsorBandResolver $sponsorBandResolver,
    ) {}

    /**
     * Builds the NPC club + player + fixture pack for a single league tier in a country.
     * Every player/coach/scout/DoF/chairman/facility-manager a club needs is generated
     * fresh, in memory, directly into the cache — never drawn from or deleted out of the
     * shared pool (see WorldPackClubGenerationService).
     *
     * **`$club` is nullable because a pre-generated WorldPackSet has no club yet**, and the
     * only thing the club was ever used for here is adding its own id to the fixture list
     * when it sits in this tier's league. That is safe to drop, and dropping it finally makes
     * the two generation paths agree: WorldPackTierAssemblyService has ALWAYS built fixtures
     * from NPC ids alone, so an admin-regenerated pack and a lazily-built one disagreed about
     * the player's own division for as long as both have existed.
     *
     * Verified not client-visible before relying on it: `wunderkind-app` types the field as
     * `fixtures: unknown; // delivered by backend, not consumed client-side (generated
     * locally)` and generates its own schedule in `useInitFlow`'s `fixtures_generated` step
     * from the league list plus its own club id. Logged as a deliberate value change in
     * `.context/shared/api-backwards-compatibility.md` with that consumer check.
     *
     * @return array{id: string, tier: int, name: string, clubs: array, fixtures: array, ...}
     */
    public function buildTierPack(?Club $club, string $country, int $tier): array
    {
        $starterConfig = $this->starterConfigRepository->getConfig();
        $poolConfig    = $this->poolConfigRepository->getConfig();
        $gameConfig    = $this->gameConfigRepository->getConfig();
        $agents        = $this->agentRepository->findAll(); // shared agent pool — many players may reference one

        $league = $this->leagueRepository->findByCountryAndTier($country, $tier);
        if ($league === null) {
            throw new \InvalidArgumentException("No league found for country={$country} tier={$tier}");
        }

        $nationality  = ClubInitializationService::countryToNationality($country) ?? $country;
        $tierConf     = $this->resolveTierConfig($tier);
        $abilityRange = $this->resolveAbilityRange($country, $tier);

        // WithLand: every club snapshot now carries a `land` block, and the plain finder would
        // leave location and landPlot lazy — two queries per club, across eight tiers.
        $npcClubs = $this->npcClubRepository->findByLeagueWithLand($league);

        // Bound the agent pool to ~worldPackPlayersPerAgent players per agent for this tier,
        // so distinct agents surfaced don't scale with the whole pool.
        $avgSquad  = ((int) $tierConf['playerMin'] + (int) $tierConf['playerMax']) / 2;
        $estimated = (int) ceil(count($npcClubs) * $avgSquad);
        $agents    = $this->selectBoundedAgentPool($agents, $estimated, $starterConfig->getWorldPackPlayersPerAgent());

        $clubsData  = [];
        $allClubIds = [];

        if ($club !== null && $club->getCurrentLeague()?->getId()->toBinary() === $league->getId()->toBinary()) {
            $allClubIds[] = (string) $club->getId();
        }

        foreach ($npcClubs as $npcClub) {
            $allClubIds[] = (string) $npcClub->getId();

            // NO COUNTRY, TIER OR REPUTATION. They used to reach generation so the turnover
            // wage model could price a world-pack player; at v11 nothing is priced at
            // generation, so a squad is a squad and the device prices it. Staff below still
            // take them — the backend keeps writing `staff.weekly_salary` for non-pack
            // consumers — and the ability BAND is still per country and tier.
            $players = $this->clubGenerator->generatePlayers(
                $tierConf, $abilityRange, $nationality, $poolConfig, $agents,
            );
            // $abilityRange IS THE PLAYER BAND AND STOPS HERE (A25, 2026-11-20). Staff and
            // scouts used to be handed it too; they now resolve their own authored
            // per-country/per-tier table from $country and $tier, because the player band is
            // POTENTIAL and a staff ability has no potential half to be measured against.
            $staff   = $this->clubGenerator->generateStaff($tierConf, $nationality, $country, $tier);
            $scouts  = $this->clubGenerator->generateScouts($tierConf, $nationality, $country, $tier);

            $clubsData[] = $this->clubGenerator->buildSnapshot($npcClub, $players, $staff, $scouts);
        }

        $fixtures = $this->fixtureGenerationService->generate($allClubIds);

        // ── STILL ROLLED, DELIBERATELY, THOUGH THE PACK NO LONGER CARRIES THE TOTAL ───────
        //
        // The pot left the pack at v11 (money is resolved on the device now), so the return
        // value is unused here. The CALL is not: `rollLeagueSponsors` mutates each
        // `LeagueSponsor::$rolledValue` — shared, country-wide server state that
        // `SyncService` sums into an existing save's league financials
        // (`SyncService.php:815`) and `SeasonSnapshot` documents as country-wide. Dropping
        // the call to tidy away an unused variable would leave every sponsor rolled at 0 for
        // the sync surface, which is a different bug in a different payload.
        $this->rollLeagueSponsors($league, $gameConfig);

        return $this->buildLeagueSnapshot($league, $clubsData, $fixtures, $gameConfig);
    }

    /**
     * Public: also called by WorldPackTierAssemblyService when folding per-club async
     * generation results into the final payload.
     *
     * ── NO LEAGUE MONEY SINCE v11 ────────────────────────────────────────────────────────
     *
     * `tvDeal`, `sponsorPot`, `prizeMoney` and `leaguePositionPot` all left together. The
     * `$sponsorPot` parameter went with them — it had no other reader, and keeping an
     * accepted-and-ignored money argument on a pack builder is exactly how the key creeps
     * back. Its ROLL still happens at both call sites, for server-side state; see there.
     *
     * The device gets all four from `GET /api/initialize/leagues`, which is UNCACHED and
     * already resolves `prizeMoney`/`leaguePositionPot` through `LeaguePayoutResolver` — so
     * re-authoring a payout ladder reaches a new save with no bump and no regeneration, which
     * is the whole point of the change. `sponsorPot` is the one it does not send: the device
     * rolls that itself from the division's published sponsor bands, off the `sponsorCount`
     * that endpoint now carries.
     *
     * `leaguePositionDecreasePercent` STAYS. It is a rule — the percentage by which each
     * finishing position's share steps down — not an amount, and the app has no other source
     * for it at init. Hard-zeroing it is a bug with a history: it paid season-1 position
     * prizes at the full pot for every position.
     */
    public function buildLeagueSnapshot(League $league, array $clubsData, array $fixtures, GameConfig $gameConfig): array
    {
        return [
            // THE GENERATION THIS PACK WAS BUILT UNDER, so a device can say which world it
            // holds and a support question can be placed on one side of a calibration or the
            // other. Server-side the same number lives on `country_world_pack_cache
            // .payload_version` and decides rebuilds; this is the client's copy of it.
            'packVersion'                   => self::WORLD_PACK_VERSION,
            'id'                            => (string) $league->getId(),
            'tier'                          => $league->getTier(),
            'name'                          => $league->getName(),
            'country'                       => $league->getCountry(),
            'promotionSpots'                => $league->getPromotionSpots(),
            'reputationTier'                => $league->getLeagueReputationTier()?->value,
            // A RULE, NOT AN AMOUNT — which is why this one survived v11 and the four money
            // keys beside it did not. See the method docblock.
            'leaguePositionDecreasePercent' => $gameConfig->getLeaguePositionDecreasePercent(),
            'trophy'                        => $league->getTrophy(),
            'clubs'                         => $clubsData,
            'fixtures'                      => $fixtures,
        ];
    }

    /**
     * Rolls sponsor pot for a league: randomises each LeagueSponsor's value within
     * the GameConfig band for its CompanySize, persists rolled values, returns the total.
     */
    /** Public: also called by WorldPackTierAssemblyService when folding per-club async generation results into the final payload. */
    public function rollLeagueSponsors(League $league, GameConfig $config): int
    {
        // DELEGATED SINCE 2026-11-12. This loop existed byte-identically in both
        // LeagueService and WorldInitializationService; SponsorBandResolver is now the one
        // implementation, and it scales the band by the league's COUNTRY — which the flat
        // columns never did, so a club in a country factored to 20% used to earn full English
        // sponsorship while paying 20% wages. Kept as a public method because external callers
        // type-hint it.
        return $this->sponsorBandResolver->rollLeagueSponsors($league, $config);
    }

    /**
     * Returns a random subset of the agent pool sized so that, once players are
     * distributed across it, each agent represents ~$playersPerAgent players
     * (target = ceil($estimatedPlayers / $playersPerAgent), never more than the
     * pool holds). This is what keeps a world pack from surfacing one agent per
     * player: `assignAgents` then draws from this bounded subset, not the whole pool.
     * No-op on an empty pool or a non-positive estimate.
     *
     * @param Agent[] $agents
     * @return Agent[]
     */
    public function selectBoundedAgentPool(array $agents, int $estimatedPlayers, int $playersPerAgent): array
    {
        if ($agents === [] || $estimatedPlayers <= 0) {
            return $agents;
        }
        $target = min(count($agents), max(1, (int) ceil($estimatedPlayers / max(1, $playersPerAgent))));
        shuffle($agents);
        return array_slice($agents, 0, $target);
    }

    /**
     * Per-tier squad composition config, live from StarterConfig with a hardcoded
     * fallback — same source of truth buildTierPack() has always used. Public so the
     * async per-club Messenger handler (WarmWorldPackClubMessageHandler) can resolve
     * the same config a synchronous buildTierPack() call would, without duplicating
     * this lookup.
     */
    public function resolveTierConfig(int $tier): array
    {
        $npcConfig = $this->starterConfigRepository->getConfig()->getNpcSquadConfig();
        $row       = $npcConfig[(string) $tier] ?? null;

        // ── A PARTIAL ROW IS FILLED, NOT TAKEN AS ZERO ───────────────────────────────────
        //
        // The generators read these with `?? 0` (`WorldPackClubGenerationService`), so a row
        // that omits `directorOfFootballCount` produced a club with no director rather than a
        // club with the default — a config edit that dropped one key silently removed a role
        // from every club in the tier.
        if (is_array($row) && $row !== []) {
            // EXCLUDES ITS OWN TIER. Without that the search finds this very row at distance 0
            // and "fills" the partial row from itself, which is a no-op that looks like a fix.
            return $row + $this->inheritedTierConfig($npcConfig, $tier, $tier);
        }

        return $this->inheritedTierConfig($npcConfig, $tier);
    }

    /**
     * What an UNCONFIGURED tier inherits — from the nearest configured tier, not from code.
     *
     * ── WHY NOT A HARDCODED TABLE ─────────────────────────────────────────────────────────
     *
     * There was one, and it disagreed with the live configuration in four places: it gave
     * tiers 3-4 **no director of football** and tiers 5-6 **no facility manager** where the
     * authored `npcSquadConfig` asks for one at every tier, it capped `coachCount` at **1**
     * against an authored 7-13, and it gave tiers 7-8 **no scout** against an authored 1.
     *
     * None of it was biting, because the table only applies where configuration is absent —
     * which is exactly what made it dangerous. It is the same defect as `app:pool:warm`'s
     * hardcoded role list: **staffing numbers living in code beside the configuration that
     * owns them, drifting quietly because nothing exercises them.**
     *
     * So an unconfigured tier now borrows from the nearest tier that IS configured, by tier
     * distance, preferring the tier ABOVE on a tie — a tier with no config of its own is far
     * more likely to resemble its neighbour than a figure somebody typed here in 2026.
     *
     * `defaultTierConfig()` remains for the one case that cannot borrow: `npcSquadConfig`
     * entirely empty, on a box where nobody has authored anything.
     *
     * @param  array<string, mixed> $npcConfig
     * @param  int|null              $excludeTier Skip this tier — used when FILLING its own
     *                                            partial row, which must inherit from a
     *                                            neighbour rather than from itself.
     * @return array<string, mixed>
     */
    private function inheritedTierConfig(array $npcConfig, int $tier, ?int $excludeTier = null): array
    {
        $best         = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($npcConfig as $key => $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }

            $candidate = (int) $key;

            if ($excludeTier !== null && $candidate === $excludeTier) {
                continue;
            }
            $distance  = abs($candidate - $tier);

            // `<` not `<=`, and the keys ascend, so an equal distance keeps the LOWER tier
            // number — the stronger division. A tier-5 gap between configured 4 and 6 takes 4.
            if ($distance < $bestDistance) {
                $best         = $row;
                $bestDistance = $distance;
            }
        }

        return $best ?? $this->defaultTierConfig($tier);
    }

    /**
     * Per-tier player ability range: StarterConfig's admin-configured league ranges
     * for this country/tier if set, else the hardcoded ABILITY_RANGES fallback. Public
     * for the same reason as resolveTierConfig() above.
     *
     * @return array{min: int, max: int}
     */
    public function resolveAbilityRange(string $country, int $tier): array
    {
        $leagueRanges = $this->starterConfigRepository->getConfig()->getLeagueAbilityRanges();
        $configured   = $leagueRanges[$country][(string) $tier] ?? null;

        return ($configured && ($configured['min'] ?? 0) > 0)
            ? ['min' => (int) $configured['min'], 'max' => (int) $configured['max']]
            : (self::ABILITY_RANGES[$tier] ?? ['min' => 5, 'max' => 35]);
    }

    /**
     * The last resort: `npcSquadConfig` is entirely empty, so there is nothing to inherit from.
     *
     * ⚠️ **Every staff role is present at 1 or more, deliberately.** The previous table zeroed
     * the director of football at tiers 3-8 and the facility manager at 5-8, which meant a box
     * with no configuration generated clubs missing roles the rest of the game assumes exist.
     * A fallback should produce a WORKING club, not a minimal one — if these figures are ever
     * reached, nobody has authored anything and the right failure is "plain" rather than
     * "subtly short-staffed".
     *
     * These are not a second opinion about what a tier should look like. `npcSquadConfig` is
     * the source of truth; anything configured anywhere is inherited in preference to this.
     *
     * @return array<string, mixed>
     */
    private function defaultTierConfig(int $tier): array
    {
        return match (true) {
            $tier <= 2 => ['playerMin' => 20, 'playerMax' => 24, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'directorOfFootballCount' => 1, 'facilityManagerCount' => 1, 'scoutCount' => 2, 'foreignPercent' => 60],
            $tier <= 4 => ['playerMin' => 16, 'playerMax' => 20, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'directorOfFootballCount' => 1, 'facilityManagerCount' => 1, 'scoutCount' => 1, 'foreignPercent' => 25],
            $tier <= 6 => ['playerMin' => 13, 'playerMax' => 17, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'directorOfFootballCount' => 1, 'facilityManagerCount' => 1, 'scoutCount' => 1, 'foreignPercent' => 12],
            default    => ['playerMin' => 11, 'playerMax' => 14, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'directorOfFootballCount' => 1, 'facilityManagerCount' => 1, 'scoutCount' => 1, 'foreignPercent' => 4],
        };
    }
}
