<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\LeagueRepository;
use App\Service\ClubInitializationService;
use App\Service\StarterPackService;
use App\Service\WorldInitializationService;
use App\Service\WorldPackCacheService;
use App\Message\WorldPack\TopUpWorldPackSetMessage;
use App\Service\WorldPack\WorldPackSetService;
use App\Service\WorldPack\WorldPackSetTopUpService;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Service\ClubResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/initialize')]
class InitializeController extends AbstractController
{
    public function __construct(
        private readonly ClubResolver               $clubResolver,
        private readonly LeagueRepository           $leagueRepository,
        private readonly StarterPackService         $starterPackService,
        private readonly WorldInitializationService $worldInitializationService,
        private readonly WorldPackCacheService      $worldPackCacheService,
        private readonly WorldPackSetService        $worldPackSetService,
        private readonly WorldPackSetTopUpService   $worldPackSetTopUpService,
        private readonly MessageBusInterface        $messageBus,
        private readonly EntityManagerInterface     $em,
        private readonly LoggerInterface            $logger,
        private readonly \App\Service\FreeAgentGenerationService $freeAgentGenerator,
    ) {}

    /**
     * POST /api/initialize/starter
     *
     * Step 1: Assigns AMP squad (players, staff, scouts) to the club and returns the starter pack.
     * Idempotent — a club that is already initialized gets its stored pack back with
     * `replayed: true`, rather than a 409.
     */
    #[Route('/starter', name: 'api_initialize_starter', methods: ['POST'])]
    public function starter(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);

        $countryParamRaw = $request->query->get('country');
        $this->logger->info('initialize.starter.request', [
            'userId'           => (string) $user->getId(),
            'clubId'           => $club?->getId() !== null ? (string) $club->getId() : null,
            'countryParamRaw'  => $countryParamRaw,
            'clubCountryBefore' => $club?->getCountry(),
            'isStarterInitialized' => $club?->isStarterInitialized(),
            'starterInitializedAt' => $club?->getStarterInitializedAt()?->format('c'),
            'currentLeagueTier' => $club?->getCurrentLeague()?->getTier(),
        ]);

        if ($club === null) {
            $this->logger->error('initialize.starter.club_not_found', ['userId' => (string) $user->getId()]);
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        // Replay rather than reject. The starter pack consumes pool entities and deletes
        // them, so a response lost in transit used to strand the player for good: the squad
        // was gone and every retry answered 409. The pack is now stored on the club at first
        // init (Club::$starterPackJson) and handed back verbatim here.
        //
        // Checked ahead of the ?country= override below on purpose: a club that already has
        // a squad must not have its country rewritten by a retry, because the stored pack
        // was drawn for the country it had at the time.
        if ($club->isStarterInitialized()) {
            $storedPack = $club->getStarterPackJson();

            if ($storedPack !== null) {
                $this->logger->info('initialize.starter.replayed', [
                    'clubId'               => (string) $club->getId(),
                    'country'              => $club->getCountry(),
                    'starterInitializedAt' => $club->getStarterInitializedAt()?->format('c'),
                ]);

                return $this->json(['ampStarter' => $storedPack, 'replayed' => true]);
            }

            // Initialized before $starterPackJson existed — there is genuinely nothing left
            // to return, since the Player/Staff rows were deleted on consume.
            $this->logger->error('initialize.starter.already_initialized_without_stored_pack', [
                'clubId' => (string) $club->getId(),
                'country' => $club->getCountry(),
                'starterInitializedAt' => $club->getStarterInitializedAt()?->format('c'),
            ]);
            return $this->json(
                [
                    'error'   => 'starter_already_initialized',
                    'message' => 'Starter already initialized, and this club predates stored starter packs.',
                ],
                Response::HTTP_CONFLICT
            );
        }

        // Accept optional ?country= override
        $countryParam = $countryParamRaw;
        if ($countryParam !== null) {
            $countryParam = strtoupper(trim($countryParam));
            if (ClubInitializationService::countryToNationality($countryParam) === null) {
                $this->logger->error('initialize.starter.unknown_country_param', [
                    'clubId' => (string) $club->getId(),
                    'countryParamRaw' => $countryParamRaw,
                    'countryParamNormalized' => $countryParam,
                ]);
                return $this->json(
                    ['error' => "Unknown country code '{$countryParam}'."],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
            $club->setCountry($countryParam);
            $this->em->flush();
        }

        if ($club->getCountry() === null && $club->getCurrentLeague() !== null) {
            $club->setCountry($club->getCurrentLeague()->getCountry());
            $this->em->flush();
        }

        if ($club->getCountry() === null) {
            $this->logger->error('initialize.starter.no_country_set', ['clubId' => (string) $club->getId()]);
            return $this->json(
                ['error' => 'Club must have a country set before initialization. Pass ?country=<code>.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // ── Claim a pre-generated set, and fall back to the live draw if there is none ────
        //
        // A set is a whole world — the starter squad below PLUS a payload for every league
        // tier — generated as one unit and claimable by exactly one club. Claiming it here
        // rather than at the first tier call is what makes the rest of initialisation
        // coherent: `tier()` reads from the club's claimed set, so a device's tiers all come
        // from one generation run. Each tier row is deleted as it is served — the pack then
        // lives on the device only.
        //
        // A null claim is a NORMAL answer, not an error. It means this country has no stock
        // right now, and the club gets exactly what every club got before sets existed: a
        // live draw here, and tiers built inline. **This fallback must stay.** A new refusal
        // at the first step of game init is unrecognisable to an already-shipped build and
        // there is no client-side escape — see
        // .context/shared/api-backwards-compatibility.md point 6.
        //
        // Claimed on $club->getCountry() AFTER the ?country= override above has been applied,
        // because that override is the client's authority on which country this save is in.
        $country = (string) $club->getCountry();
        $set     = $this->worldPackSetService->claimForClub($club, $country);

        if ($set !== null) {
            // One flush for all three writes, preserving the guarantee
            // Club::$starterPackJson was introduced for: the pack is recorded at the same
            // moment the club is marked initialised, so a response lost in transit can always
            // be replayed and never leaves the club initialised with nothing.
            $ampStarter = $set->getStarterPackJson();
            $club->setStarterPackJson($ampStarter);
            $club->setStarterInitializedAt(new \DateTimeImmutable());
            $this->em->flush();

            $this->logger->info('initialize.starter.served_from_set', [
                'clubId'  => (string) $club->getId(),
                'country' => $country,
                'setId'   => (string) $set->getId(),
            ]);

            $this->dispatchTopUp($country);

            return $this->json(['ampStarter' => $ampStarter]);
        }

        // ── The live fallback ─────────────────────────────────────────────────────────────
        //
        // Reached when the country has no ready set. It GENERATES the squad rather than drawing
        // it from the shared pool, exactly as the pre-generated sets do, so the two paths differ
        // only in when the work happens and never in where the players came from.
        //
        // There is nothing left to refuse on. The `MIN_POOL_SIZE` guard and the
        // `412 InsufficientStarterPoolException` that used to sit here are both gone, because
        // generation has no supply to be short of — a new game can no longer be refused for a
        // thin pool, which was the one failure in this flow with no client-side escape.
        $ampStarter = $this->starterPackService->initialize($club);

        $this->logger->info('initialize.starter.served_from_live_generation', [
            'clubId'  => (string) $club->getId(),
            'country' => $country,
            'hint'    => 'no ready set for this country; squad generated now, tiers built inline and uncached',
        ]);

        $this->dispatchTopUp($country);

        return $this->json(['ampStarter' => $ampStarter]);
    }

    /**
     * POST /api/initialize/free-agents
     *
     * The world's free agents (pack v16, Courtney 2026-11-27): the claimed set's list, loaded by
     * the device straight into the save's free transfer list. Replays the same list on retry,
     * like a tier, until the maintain sweep clears it an hour after the world finished loading.
     *
     *  - Claimed set holding a list → that list, `servedFromSet: true`.
     *  - Claimed set built before v16 → generated now (it never had a list to mix with).
     *  - Claimed v16+ set whose list was swept → 410, never a fresh list: a world must not be
     *    mixed (Courtney, 2026-11-23). The client skips the pool with a warning.
     *  - No set (live fallback world) → generated now, like the live starter squad.
     */
    #[Route('/free-agents', name: 'api_initialize_free_agents', methods: ['POST'])]
    public function freeAgents(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);

        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$club->isStarterInitialized()) {
            return $this->json(
                ['error' => 'Call POST /api/initialize/starter before fetching free agents.'],
                Response::HTTP_PRECONDITION_FAILED
            );
        }

        $country = (string) $club->getCountry();
        $set     = $club->getWorldPackSet();
        $players = $set?->getFreeAgentsJson();
        $servedFromSet = $players !== null;

        if ($set !== null && $players === null
            && $set->getPayloadVersion() >= WorldInitializationService::FREE_AGENTS_SINCE_PACK_VERSION) {
            $this->logger->warning('initialize.free_agents.missing_from_claimed_set', [
                'clubId'  => (string) $club->getId(),
                'country' => $country,
                'setId'   => (string) $set->getId(),
            ]);

            return $this->json(
                [
                    'error'   => 'world_pack_free_agents_unavailable',
                    'message' => "This club's free agent list is no longer available.",
                ],
                Response::HTTP_GONE
            );
        }

        if ($players === null) {
            $players = $this->freeAgentGenerator->generateForPack($country);
        }

        $this->logger->info('initialize.free_agents.served', [
            'clubId'        => (string) $club->getId(),
            'country'       => $country,
            'servedFromSet' => $servedFromSet,
            'setId'         => $set !== null ? (string) $set->getId() : null,
            'players'       => count($players),
        ]);

        return $this->json([
            'country'       => $country,
            'servedFromSet' => $servedFromSet,
            'players'       => $players,
        ]);
    }

    /**
     * GET /api/initialize/land[?country=XX]
     *
     * The land under every NPC club in the country (default: the caller's club's), money
     * included: location and plot ids, plot counts and free plots, the plot's and the town's
     * catalogue price. UNCACHED, so prices never enter a world pack — see NpcClubLandService.
     *
     * No starter precondition: a save built before this endpoint existed backfills from it.
     */
    #[Route('/land', name: 'api_initialize_land', methods: ['GET'])]
    public function land(Request $request, \App\Service\Location\NpcClubLandService $land): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $country = strtoupper(trim((string) $request->query->get('country', '')));

        if ($country === '') {
            $club = $this->clubResolver->resolveFromRequest($user);

            if ($club === null) {
                return $this->json(['error' => 'Club not found; pass ?country=XX.'], Response::HTTP_NOT_FOUND);
            }

            $country = (string) $club->getCountry();
        }

        return $this->json(['country' => $country, 'clubs' => $land->forCountry($country)]);
    }

    /**
     * GET /api/initialize/leagues
     *
     * Step 2: Returns lightweight league metadata for the club's country (no NPC squads).
     * Requires starter to be initialized first.
     */
    #[Route('/leagues', name: 'api_initialize_leagues', methods: ['GET'])]
    public function leagues(
        \App\Service\Economy\LeagueSizeResolver $leagueSizeResolver,
        \App\Service\Economy\LeaguePayoutResolver $leaguePayoutResolver,
        \App\Repository\GameConfigRepository $gameConfigRepository,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);

        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$club->isStarterInitialized()) {
            return $this->json(
                ['error' => 'Call POST /api/initialize/starter before fetching leagues.'],
                Response::HTTP_PRECONDITION_FAILED
            );
        }

        $leagues    = $this->leagueRepository->findByCountry($club->getCountry());
        $gameConfig = $gameConfigRepository->getConfig();

        $data = array_map(fn($league) => [
            'id'                => (string) $league->getId(),
            'tier'              => $league->getTier(),
            'name'              => $league->getName(),
            'country'           => $league->getCountry(),
            'promotionSpots'    => $league->getPromotionSpots(),
            'reputationTier'    => $league->getLeagueReputationTier()?->value,
            // RESOLVED, exactly like the payouts here. `tvDeal` is DERIVED whenever a pool is
            // authored — `LeagueTierDefaultDiff:103` already states that rule — but
            // `syncTvDeal()` runs ONLY on an admin save (`LeagueAdminController:95`/`:201`) and
            // `LeagueService:82` deliberately skips it at generation, so a generated pooled
            // league keeps a NULL column for ever. Publishing the raw column therefore sent
            // NULL, and the app's `Math.max(0, league.tvDealPence)` (`seasonFinances.ts:80`)
            // turned that into £0 of TV income. `derivedTvDealPence()` returns null when there
            // is no pool, so this is INERT for every league that authors its deal directly.
            'tvDeal'            => $leagueSizeResolver->tvDealFor($league),
            // RESOLVED, the same chain the sync snapshot and the world pack use: a null
            // column inherits the tier-1 base x this tier's ratio x the country factor.
            'prizeMoney'        => $leaguePayoutResolver->prizeFor($league, $gameConfig),
            'leaguePositionPot' => $leaguePayoutResolver->positionPotFor($league, $gameConfig),
            // ADDITIVE. This endpoint is uncached, so unlike the world pack it can carry the
            // two new keys without a WORLD_PACK_VERSION bump.
            'tvPoolPence'       => $leagueSizeResolver->tvPoolPenceFor($league),
            'clubCount'         => $leagueSizeResolver->sizeOf($league),
            // ── THE COUNT, NOT THE POT (v11, 2026-11-18) ─────────────────────────────────
            //
            // `sponsorPot` left the world pack with the rest of the money, and this endpoint
            // deliberately does not replace it with a pot of its own: the device already rolls
            // one. `rollLeagueSponsorPot` (app `utils/leagueFinances.ts`) draws each sponsor
            // from the DIVISION's published sponsor bands, and `SeasonTransitionService` has
            // called it at every season boundary for months — season 1 was the only one taking
            // a server figure instead. Now it rolls there too.
            //
            // So what the device is missing is not a price, it is CENSUS data: how many
            // sponsors this league has, and what size they are. Same category as `clubCount`
            // directly above, and the roll needs both to be faithful:
            //
            //   - `sponsorCount` — the authored column. The app's fallback path uses it with a
            //     reputation-derived size mix.
            //   - `sponsorSizes` — the ACTUAL mix, counted off this league's `LeagueSponsor`
            //     rows. Preferred by the app when present, and it makes the device's roll draw
            //     from the same bands for the same sponsors the server would have rolled. Keys
            //     are upper-cased because `CompanySize` is lower-case here and the app's
            //     `CompanySize` is upper-case; this is the boundary, so it converts here.
            //
            // ⚠️ Do NOT let the app fall back to `?? 0` for the pot instead of this. That
            // hard-zero has a history: season-1 position prizes paid at the full pot for every
            // finishing position. Supplying the count is the fix; tolerating the absence is not.
            'sponsorCount'      => $league->getSponsorCount(),
            'sponsorSizes'      => $this->sponsorSizesFor($league),
            // Resolved whole-value, `league ?? gameConfig` — the same resolution the sync
            // snapshot and the season pyramid do, so a client reading any of the three gets
            // one answer.
            'parachute'         => $leaguePayoutResolver->parachuteFor($league, $gameConfig),
            'trophy'            => $league->getTrophy(),
        ], $leagues);

        // ── packVersion, AND IT MEANS SOMETHING SLIGHTLY DIFFERENT HERE ───────────────────
        //
        // On a tier pack this key is the version that pack WAS BUILT AT — a fact about the
        // payload in your hand. This response is not a pack: it is an uncached list assembled
        // live from `league` rows, with no version of its own. So here it is the version the
        // server builds AT, which is what the next `POST /api/initialize/league/{tier}` will
        // stamp whatever it returns.
        //
        // Published because a client wants to know before fetching eight tiers, not after. The
        // distinction matters when they disagree: during a regeneration this reads 5 while a
        // not-yet-rebuilt pack still holds 4, and the honest reading of that is "the packs you
        // are about to fetch will be rebuilt", not "you already have version 5".
        //
        // Additive sibling of `leagues` — this endpoint has always returned an object, so no
        // client sees a shape change. Uncached, so no WORLD_PACK_VERSION bump is needed to add
        // it, unlike the pack itself.
        return $this->json([
            'leagues'     => $data,
            'packVersion' => WorldInitializationService::WORLD_PACK_VERSION,
        ]);
    }

    /**
     * This league's sponsor roster counted by company size, e.g. `['SMALL' => 3, 'MEDIUM' => 1]`.
     *
     * Census data, not a price — see the `sponsorSizes` comment in `leagues()`. The device rolls
     * the pot itself from the division's published bands; this is what tells it how many
     * sponsors of which size to roll for, so season 1 draws the same shape the server would.
     *
     * NULL WHEN THE LEAGUE HAS NO `LeagueSponsor` ROWS, rather than an empty array: the app
     * treats a present `sponsorSizes` as authoritative and an absent one as "fall back to
     * `sponsorCount`". An empty object would be read as "this league has no sponsors" and roll a
     * pot of nothing, which is wrong for a league whose `sponsorCount` is authored but whose
     * sponsors have not been sourced yet.
     *
     * Keys are UPPER-CASED at this boundary: `App\Enum\CompanySize` is lower-case ('small'),
     * the app's `CompanySize` union is upper-case ('SMALL'), and the app indexes its band table
     * by it directly.
     *
     * @return array<string, int>|null
     */
    private function sponsorSizesFor(\App\Entity\League $league): ?array
    {
        $sizes = [];

        foreach ($league->getLeagueSponsors() as $leagueSponsor) {
            $key = strtoupper($leagueSponsor->getSponsor()->getSize()->value);
            $sizes[$key] = ($sizes[$key] ?? 0) + 1;
        }

        return $sizes === [] ? null : $sizes;
    }

    /**
     * POST /api/initialize/league/{tier}
     *
     * Step 3: Returns NPC clubs + squads + fixtures for one league tier.
     * Serves the tier from the club's claimed set and stamps it served; a retry replays the same
     * bytes. A claimed set missing this tier answers 410 rather than building a tier from
     * another generation. A club with no set gets the tier built inline. Sets
     * worldInitializedAt once every tier of the club's set has been served.
     */
    #[Route('/league/{tier}', name: 'api_initialize_league_tier', requirements: ['tier' => '\d+'], methods: ['POST'])]
    public function tier(int $tier): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);

        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$club->isStarterInitialized()) {
            return $this->json(
                ['error' => 'Call POST /api/initialize/starter before fetching league tiers.'],
                Response::HTTP_PRECONDITION_FAILED
            );
        }

        $country = $club->getCountry();
        $league  = $this->leagueRepository->findByCountryAndTier($country, $tier);

        if ($league === null) {
            return $this->json(
                ['error' => "No league found for country={$country} tier={$tier}."],
                Response::HTTP_NOT_FOUND
            );
        }

        $startedAt = microtime(true);
        $set       = $club->getWorldPackSet();

        // Served from the club's own set: the ordinary path. The row is KEPT and only stamped
        // as served (below), so a retried or restarted load gets the exact same bytes. A
        // claimed set is read at whatever version it was built, because it is one world.
        $payload = $set !== null
            ? $this->worldPackCacheService->readClaimedTier($set, $tier)
            : null;

        if ($set !== null && $payload === null) {
            // The club owns a world but this tier of it is gone (an abandoned load pruned by
            // the retention backstop). Building one now would hand the device a tier from
            // another generation: a mixed world, which must never happen (Courtney,
            // 2026-11-23). Refuse loudly instead.
            $this->logger->error('initialize.league_tier.missing_from_claimed_set', [
                'clubId'  => (string) $club->getId(),
                'country' => $country,
                'tier'    => $tier,
                'setId'   => (string) $set->getId(),
            ]);

            return $this->json(
                [
                    'error' => 'world_pack_tier_unavailable',
                    'message' => "Tier {$tier} of this club's world is no longer available. Start a new game.",
                ],
                Response::HTTP_GONE
            );
        }

        $servedFromSet = $payload !== null;

        if (!$servedFromSet) {
            // Built inline and DELIBERATELY NOT CACHED.
            //
            // Two clubs reach this: one that predates sets, and one whose country had no stock
            // when it claimed. Writing the result to the cache would need a row keyed on
            // (country, tier) — which is exactly the ambiguity sets removed, since a country
            // now holds several worlds and nothing could say which this row belonged to.
            //
            // The cost is that a retry rebuilds rather than replaying: ~2-4s, the same as any
            // cold tier before this change, and the client's retry is idempotent in the only
            // sense that matters here — it gets a valid, complete tier. Not writing anything
            // is also why the advisory lock that used to serialise cold builds is gone: there
            // is no insert left to race on.
            $payload = $this->worldInitializationService->buildTierPack($club, $country, $tier);
        }

        // What this tier actually weighs, per response.
        //
        // The client reports slow device-side writes as `db: slow write` with a row count
        // and no country or tier attached (Sentry `153689847`: 6,992 staff rows in 3.6s),
        // so an outlier cannot be placed against a payload — and no cached pack can
        // currently produce a number anywhere near that one: the ceiling across every
        // cached tier is 360 staff + scouts. Logging it here makes the next occurrence
        // correlatable by timestamp from this side, with no client change and no new
        // Sentry label. Keep `staff`/`scouts`/`players` counted the way the client counts
        // them — per entity array, per tier, not cumulative.
        $this->logger->info('initialize.league_tier.served', [
            'clubId'       => (string) $club->getId(),
            'country'      => $country,
            'tier'         => $tier,
            // Kept under its original key: `cacheHit` has always meant "did this response
            // avoid a build", and being served from a set is exactly that. `servedFromSet`
            // and `setId` are additive, so existing log queries keep working.
            'cacheHit'     => $servedFromSet,
            'servedFromSet' => $servedFromSet,
            'setId'        => $set !== null ? (string) $set->getId() : null,
            'durationMs'   => (int) round((microtime(true) - $startedAt) * 1000),
            'clubs'        => count($payload['clubs'] ?? []),
            'players'      => $this->countNested($payload, 'players'),
            'staff'        => $this->countNested($payload, 'staff'),
            'scouts'       => $this->countNested($payload, 'scouts'),
            'payloadBytes' => strlen((string) json_encode($payload)),
        ]);

        // Stamp the tier as handed over, and mark the world complete once every tier has been.
        // The rows stay until the hourly sweep clears worlds finished more than an hour ago
        // (`app:worldpack:maintain`), so the server still holds nothing for a finished save.
        if ($set !== null) {
            $this->worldPackCacheService->markServed($set, $tier);

            if (!$club->isWorldInitialized() && $this->worldPackCacheService->allServed($set)) {
                $club->setWorldInitializedAt(new \DateTimeImmutable());
                $this->em->flush();
            }
        }

        return $this->json(['tier' => $tier, 'data' => $payload]);
    }

    /**
     * Asks for $country to be restocked, if it is short.
     *
     * Gated on `needsTopUp()` so the common path — a healthy country — writes nothing to the
     * queue at all. Wrapped, because a transport failure must never turn a successful
     * initialisation into an error response: the hourly `app:worldpack:maintain` sweep is the
     * backstop for exactly this, so a lost message costs at most one slow init later.
     */
    private function dispatchTopUp(string $country): void
    {
        try {
            if (!$this->worldPackSetTopUpService->needsTopUp($country)) {
                return;
            }

            $this->messageBus->dispatch(new TopUpWorldPackSetMessage($country));
        } catch (\Throwable $e) {
            $this->logger->error('initialize.starter.topup_dispatch_failed', [
                'country' => $country,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** Total entries in `$key` across every club in a tier payload. */
    private function countNested(array $payload, string $key): int
    {
        $total = 0;
        foreach ($payload['clubs'] ?? [] as $club) {
            $total += count($club[$key] ?? []);
        }

        return $total;
    }
}
