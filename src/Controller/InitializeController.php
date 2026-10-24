<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\InsufficientStarterPoolException;
use App\Repository\LeagueRepository;
use App\Repository\PlayerRepository;
use App\Service\ClubInitializationService;
use App\Service\StarterPackService;
use App\Service\WorldInitializationService;
use App\Service\WorldPackCacheService;
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
    private const MIN_POOL_SIZE = 500;

    public function __construct(
        private readonly ClubResolver               $clubResolver,
        private readonly PlayerRepository           $playerRepository,
        private readonly LeagueRepository           $leagueRepository,
        private readonly StarterPackService         $starterPackService,
        private readonly WorldInitializationService $worldInitializationService,
        private readonly WorldPackCacheService      $worldPackCacheService,
        private readonly EntityManagerInterface     $em,
        private readonly LoggerInterface            $logger,
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

        $poolCount = $this->playerRepository->countInPool();
        if ($poolCount < self::MIN_POOL_SIZE) {
            $this->logger->error('initialize.starter.global_pool_too_small', [
                'clubId' => (string) $club->getId(),
                'poolCount' => $poolCount,
                'minPoolSize' => self::MIN_POOL_SIZE,
            ]);
            return $this->json(
                ['error' => "Player pool too small ({$poolCount} players). Run GenerateMarketDataCommand first."],
                Response::HTTP_PRECONDITION_FAILED
            );
        }

        try {
            $ampStarter = $this->starterPackService->initialize($club);
        } catch (InsufficientStarterPoolException $e) {
            // Club::$starterInitializedAt was deliberately left untouched on this path, so the
            // client can safely retry once the pool has been warmed for this country.
            $this->logger->error('initialize.starter.insufficient_pool', [
                'clubId' => (string) $club->getId(),
                'country' => $club->getCountry(),
                'message' => $e->getMessage(),
            ]);
            return $this->json(['error' => $e->getMessage()], Response::HTTP_PRECONDITION_FAILED);
        }

        return $this->json(['ampStarter' => $ampStarter]);
    }

    /**
     * GET /api/initialize/leagues
     *
     * Step 2: Returns lightweight league metadata for the club's country (no NPC squads).
     * Requires starter to be initialized first.
     */
    #[Route('/leagues', name: 'api_initialize_leagues', methods: ['GET'])]
    public function leagues(): JsonResponse
    {
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

        $leagues = $this->leagueRepository->findByCountry($club->getCountry());

        $data = array_map(fn($league) => [
            'id'                => (string) $league->getId(),
            'tier'              => $league->getTier(),
            'name'              => $league->getName(),
            'country'           => $league->getCountry(),
            'promotionSpots'    => $league->getPromotionSpots(),
            'reputationTier'    => $league->getLeagueReputationTier()?->value,
            'tvDeal'            => $league->getTvDeal(),
            'prizeMoney'        => $league->getPrizeMoney(),
            'leaguePositionPot' => $league->getLeaguePositionPot(),
            'trophy'            => $league->getTrophy(),
        ], $leagues);

        return $this->json(['leagues' => $data]);
    }

    /**
     * POST /api/initialize/league/{tier}
     *
     * Step 3: Returns NPC clubs + squads + fixtures for one league tier.
     * Generates and caches on first call; subsequent calls (including retries) return cached data.
     * Sets worldInitializedAt once all tiers for the country are cached.
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

        $cacheHit = $this->worldPackCacheService->isCached($country, $tier);
        $startedAt = microtime(true);

        $payload = $this->worldPackCacheService->getOrBuild(
            $country,
            $tier,
            fn() => $this->worldInitializationService->buildTierPack($club, $country, $tier)
        );

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
            'cacheHit'     => $cacheHit,
            'durationMs'   => (int) round((microtime(true) - $startedAt) * 1000),
            'clubs'        => count($payload['clubs'] ?? []),
            'players'      => $this->countNested($payload, 'players'),
            'staff'        => $this->countNested($payload, 'staff'),
            'scouts'       => $this->countNested($payload, 'scouts'),
            'payloadBytes' => strlen((string) json_encode($payload)),
        ]);

        // Check if all tiers are now cached → mark world as fully initialized
        if (!$club->isWorldInitialized()) {
            $allTiers = array_map(
                fn($l) => $l->getTier(),
                $this->leagueRepository->findByCountry($country)
            );
            if ($this->worldPackCacheService->allTiersCached($country, $allTiers)) {
                $club->setWorldInitializedAt(new \DateTimeImmutable());
                $this->em->flush();
            }
        }

        return $this->json(['tier' => $tier, 'data' => $payload]);
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
