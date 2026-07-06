<?php

namespace App\Controller\Api;

use App\Dto\ClubInitRequest;
use App\Entity\Investor;
use App\Entity\User;
use App\Repository\ClubRepository;
use App\Repository\NpcClubRepository;
use App\Service\ClubInitializationService;
use App\Service\NpcClubGenerationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/club')]
class ClubController extends AbstractController
{
    public function __construct(private readonly ClubRepository $clubRepository) {}

    #[Route('/foreign', name: 'api_clubs_foreign', methods: ['GET'])]
    public function foreignClubs(Request $request, NpcClubRepository $npcClubRepo): JsonResponse
    {
        $country      = strtoupper($request->query->get('country', 'EN'));
        $limitPerTier = max(1, min(10, (int) $request->query->get('limit_per_tier', 3)));

        $clubs = $npcClubRepo->findForeignClubs($country, null, $limitPerTier);

        return $this->json([
            'country' => $country,
            'clubs'   => $clubs,
        ]);
    }

    #[Route('/name-options', name: 'api_clubs_name_options', methods: ['GET'])]
    public function nameOptions(Request $request, NpcClubGenerationService $npcClubGenerationService): JsonResponse
    {
        $country  = strtoupper($request->query->get('country', 'EN'));
        $cities   = $npcClubGenerationService->getPlaceNames($country) ?: $npcClubGenerationService->getPlaceNames('EN');
        $suffixes = $npcClubGenerationService->getSuffixes($country) ?: $npcClubGenerationService->getSuffixes('EN');

        return $this->json([
            'country'  => $country,
            'cities'   => $cities,
            'suffixes' => $suffixes,
        ]);
    }

    #[Route('/initialize', name: 'api_club_initialize', methods: ['POST'])]
    #[IsGranted('ROLE_CLUB')]
    public function initialize(
        #[MapRequestPayload] ClubInitRequest $dto,
        ClubInitializationService $service,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        $managerProfile = $dto->manager !== null ? [
            'name'        => $dto->manager->name,
            'dateOfBirth' => $dto->manager->dateOfBirth,
            'gender'      => $dto->manager->gender,
            'nationality' => $dto->manager->nationality,
        ] : null;

        try {
            $club = $service->initializeClub($user, $dto->clubName, $dto->country, $managerProfile);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'id'            => $club->getId()->toRfc4122(),
            'name'          => $club->getName(),
            'starterBundle' => $service->getStarterBundle(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/check', name: 'api_club_check', methods: ['GET'])]
    #[IsGranted('ROLE_CLUB')]
    public function check(): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $club = $this->clubRepository->findByUser($user);

        if ($club === null) {
            return $this->json(['exists' => false, 'reason' => 'club_not_found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['exists' => true, 'clubId' => $club->getId()->toRfc4122()]);
    }

    #[Route('/status', name: 'api_club_status', methods: ['GET'])]
    #[IsGranted('ROLE_CLUB')]
    public function status(): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $club = $this->clubRepository->findByUser($user);

        if ($club === null) {
            return $this->json(['error' => 'No club found.'], Response::HTTP_NOT_FOUND);
        }

        $activeInvestorCount = $club->getInvestors()
            ->filter(fn (Investor $i) => $i->isActive())
            ->count();

        return $this->json([
            'id'                  => $club->getId()->toRfc4122(),
            'name'                => $club->getName(),
            'abbreviation'        => $club->getAbbreviation(),
            'balance'             => $club->getBalance(),
            'hasDebt'             => $club->hasDebt(),
            'reputation'          => $club->getReputation(),
            'weekNumber'          => $club->getLastSyncedWeek(),
            'totalCareerEarnings' => $club->getTotalCareerEarnings(),
            'hallOfFamePoints'    => $club->getHallOfFamePoints(),
            'activeSponsors'      => $club->getActiveSponsors()->count(),
            'activeInvestors'     => $activeInvestorCount,
            'tutorialCompletedAt' => $club->getTutorialCompletedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }
}
