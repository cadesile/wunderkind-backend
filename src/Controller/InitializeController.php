<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\AcademyRepository;
use App\Service\WorldInitializationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class InitializeController extends AbstractController
{
    public function __construct(
        private readonly AcademyRepository          $academyRepository,
        private readonly WorldInitializationService $worldInitializationService,
    ) {}

    /**
     * POST /api/initialize
     *
     * One-time world initialization. Assembles the full world pack for the academy's
     * country and returns it to the client. Guards:
     *   - 422 if academy has no country set
     *   - 409 if already initialized (worldInitializedAt is set)
     */
    #[Route('/initialize', name: 'api_initialize', methods: ['POST'])]
    public function initialize(): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $academy = $this->academyRepository->findByUser($user);

        if ($academy === null) {
            return $this->json(['error' => 'Academy not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($academy->getCountry() === null) {
            return $this->json(
                ['error' => 'Academy must have a country set before initialization.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($academy->isWorldInitialized()) {
            return $this->json(
                ['error' => 'World already initialized for this academy.'],
                Response::HTTP_CONFLICT,
            );
        }

        $worldPack = $this->worldInitializationService->initialize($academy);

        return $this->json(['worldPack' => $worldPack]);
    }
}
