<?php

namespace App\Controller\Api;

use App\Repository\StarterConfigRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public endpoint — no JWT required.
 * Called by the client before the user has credentials,
 * to know how to initialise a fresh academy.
 */
#[Route('/api')]
class StarterConfigController extends AbstractController
{
    public function __construct(
        private readonly StarterConfigRepository $starterConfigRepository,
    ) {}

    #[Route('/starter-config', name: 'api_starter_config', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $config = $this->starterConfigRepository->getConfig();

        return $this->json([
            'startingBalance'    => $config->getStartingBalance(),
            'starterPlayerCount' => $config->getStarterPlayerCount(),
            'starterCoachCount'  => $config->getStarterCoachCount(),
            'starterScoutCount'  => $config->getStarterScoutCount(),
            'starterSponsorTier'  => $config->getStarterSponsorTier(),
            'starterAcademyTier'  => $config->getStarterAcademyTier(),
        ]);
    }
}
