<?php

namespace App\Controller\Api;

use App\Dto\AcademyInitRequest;
use App\Entity\Investor;
use App\Entity\User;
use App\Service\AcademyInitializationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/academy')]
class AcademyController extends AbstractController
{
    #[Route('/initialize', name: 'api_academy_initialize', methods: ['POST'])]
    #[IsGranted('ROLE_ACADEMY')]
    public function initialize(
        #[MapRequestPayload] AcademyInitRequest $dto,
        AcademyInitializationService $service,
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
            $academy = $service->initializeAcademy($user, $dto->academyName, $dto->country, $managerProfile);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'id'            => $academy->getId()->toRfc4122(),
            'name'          => $academy->getName(),
            'starterBundle' => $service->getStarterBundle(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/check', name: 'api_academy_check', methods: ['GET'])]
    #[IsGranted('ROLE_ACADEMY')]
    public function check(): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $academy = $user->getAcademy();

        if ($academy === null) {
            return $this->json(['exists' => false, 'reason' => 'academy_not_found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['exists' => true, 'academyId' => $academy->getId()->toRfc4122()]);
    }

    #[Route('/status', name: 'api_academy_status', methods: ['GET'])]
    #[IsGranted('ROLE_ACADEMY')]
    public function status(): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $academy = $user->getAcademy();

        if ($academy === null) {
            return $this->json(['error' => 'No academy found.'], Response::HTTP_NOT_FOUND);
        }

        $activeInvestorCount = $academy->getInvestors()
            ->filter(fn (Investor $i) => $i->isActive())
            ->count();

        return $this->json([
            'id'                  => $academy->getId()->toRfc4122(),
            'name'                => $academy->getName(),
            'balance'             => $academy->getBalance(),
            'hasDebt'             => $academy->hasDebt(),
            'reputation'          => $academy->getReputation(),
            'weekNumber'          => $academy->getLastSyncedWeek(),
            'totalCareerEarnings' => $academy->getTotalCareerEarnings(),
            'hallOfFamePoints'    => $academy->getHallOfFamePoints(),
            'playerCount'         => $academy->getPlayers()->count(),
            'staffCount'          => $academy->getStaff()->count(),
            'activeSponsors'      => $academy->getActiveSponsors()->count(),
            'activeInvestors'     => $activeInvestorCount,
        ]);
    }
}
