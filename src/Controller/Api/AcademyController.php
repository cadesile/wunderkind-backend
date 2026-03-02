<?php

namespace App\Controller\Api;

use App\Dto\AcademyInitRequest;
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

        try {
            $academy = $service->initializeAcademy($user, $dto->academyName);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'id'            => $academy->getId()->toRfc4122(),
            'name'          => $academy->getName(),
            'starterBundle' => $service->getStarterBundle(),
            'players'       => $academy->getPlayers()->count(),
            'staff'         => $academy->getStaff()->count(),
        ], Response::HTTP_CREATED);
    }
}
