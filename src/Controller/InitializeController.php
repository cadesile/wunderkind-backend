<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\LeagueRepository;
use App\Repository\PlayerRepository;
use App\Service\ClubInitializationService;
use App\Service\StarterPackService;
use App\Service\WorldInitializationService;
use App\Service\WorldPackCacheService;
use App\Service\ClubResolver;
use Doctrine\ORM\EntityManagerInterface;
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
    ) {}

    /**
     * POST /api/initialize/starter
     *
     * Step 1: Assigns AMP squad (players, staff, scouts) to the club and returns the starter pack.
     * Idempotent — returns starter data again if already initialized.
     */
    #[Route('/starter', name: 'api_initialize_starter', methods: ['POST'])]
    public function starter(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);

        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        // Accept optional ?country= override
        $countryParam = $request->query->get('country');
        if ($countryParam !== null) {
            $countryParam = strtoupper(trim($countryParam));
            if (ClubInitializationService::countryToNationality($countryParam) === null) {
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
            return $this->json(
                ['error' => 'Club must have a country set before initialization. Pass ?country=<code>.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $poolCount = $this->playerRepository->countInPool();
        if ($poolCount < self::MIN_POOL_SIZE) {
            return $this->json(
                ['error' => "Player pool too small ({$poolCount} players). Run GenerateMarketDataCommand first."],
                Response::HTTP_PRECONDITION_FAILED
            );
        }

        if ($club->isStarterInitialized()) {
            return $this->json(
                ['error' => 'Starter already initialized.'],
                Response::HTTP_CONFLICT
            );
        }

        $ampStarter = $this->starterPackService->initialize($club);

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
            'trophyImage'       => $league->getTrophyImage(),
            'trophyColour'      => $league->getTrophyColour()?->value,
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

        $payload = $this->worldPackCacheService->getOrBuild(
            $country,
            $tier,
            fn() => $this->worldInitializationService->buildTierPack($club, $country, $tier)
        );

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
}
