<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Enum\FacilityType;
use App\Repository\FacilityRepository;
use App\Service\FacilityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/facilities')]
#[IsGranted('ROLE_ACADEMY')]
class FacilityController extends AbstractController
{
    #[Route('', name: 'api_facilities_index', methods: ['GET'])]
    public function index(FacilityService $facilityService): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $academy = $user->getAcademy();

        if ($academy === null) {
            return $this->json(['error' => 'No academy found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['facilities' => $facilityService->getAcademyFacilitiesData($academy)]);
    }

    #[Route('/{type}/upgrade', name: 'api_facilities_upgrade', methods: ['POST'])]
    public function upgrade(
        string $type,
        FacilityRepository $facilityRepository,
        FacilityService    $facilityService,
    ): JsonResponse {
        /** @var User $user */
        $user    = $this->getUser();
        $academy = $user->getAcademy();

        if ($academy === null) {
            return $this->json(['error' => 'No academy found.'], Response::HTTP_NOT_FOUND);
        }

        $facilityType = FacilityType::tryFrom($type);
        if ($facilityType === null) {
            return $this->json(['error' => 'Invalid facility type.'], Response::HTTP_BAD_REQUEST);
        }

        $facility = $facilityRepository->findByAcademyAndType($academy, $facilityType);
        if ($facility === null) {
            return $this->json(['error' => 'Facility not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $facilityService->upgradeFacility($facility);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'type'          => $facility->getTypeValue(),
            'level'         => $facility->getLevel(),
            'canUpgrade'    => $facility->canUpgrade(),
            'upgradeCost'   => $facility->getUpgradeCost(),
            'currentEffect' => $facility->getCurrentEffect(),
            'balance'       => $academy->getBalance(),
        ]);
    }
}
