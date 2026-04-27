<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ClubRepository;
use App\Repository\PlayerRepository;
use App\Service\ClubInitializationService;
use App\Service\WorldInitializationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class InitializeController extends AbstractController
{
    private const MIN_POOL_SIZE = 500;

    public function __construct(
        private readonly ClubRepository             $clubRepository,
        private readonly PlayerRepository           $playerRepository,
        private readonly WorldInitializationService $worldInitializationService,
        private readonly EntityManagerInterface     $em,
    ) {}

    /**
     * POST /api/initialize
     *
     * One-time world initialization. Assembles the full world pack for the club's
     * country and returns it to the client. Guards:
     *   - 422 if club has no country set
     *   - 409 if already initialized (worldInitializedAt is set)
     *   - 412 if player pool has fewer than MIN_POOL_SIZE players
     */
    #[Route('/initialize', name: 'api_initialize', methods: ['POST'])]
    public function initialize(Request $request): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $club = $this->clubRepository->findByUser($user);

        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        // Accept an optional ?country=EN query param — overrides whatever is on the club.
        $countryParam = $request->query->get('country');
        if ($countryParam !== null) {
            $countryParam = strtoupper(trim($countryParam));
            if (ClubInitializationService::countryToNationality($countryParam) === null) {
                return $this->json(
                    ['error' => "Unknown country code '{$countryParam}'."],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
            $club->setCountry($countryParam);
            $this->em->flush();
        }

        // Fallback: derive country from the club's assigned league if still unset.
        if ($club->getCountry() === null && $club->getCurrentLeague() !== null) {
            $club->setCountry($club->getCurrentLeague()->getCountry());
            $this->em->flush();
        }

        if ($club->getCountry() === null) {
            return $this->json(
                ['error' => 'Club must have a country set before initialization. Pass ?country=<code>.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($club->isWorldInitialized()) {
            return $this->json(
                ['error' => 'World already initialized for this club.'],
                Response::HTTP_CONFLICT,
            );
        }

        $poolCount = $this->playerRepository->countInPool();
        if ($poolCount < self::MIN_POOL_SIZE) {
            return $this->json(
                ['error' => "Player pool too small ({$poolCount} players). Run GenerateMarketDataCommand first."],
                Response::HTTP_PRECONDITION_FAILED,
            );
        }

        $worldPack = $this->worldInitializationService->initialize($club);

        return $this->json(['worldPack' => $worldPack]);
    }
}
