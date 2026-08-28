<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\WorldOverviewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public endpoint — no JWT required.
 *
 * Publishes the shape of the game world (playable countries, league pyramid,
 * starting conditions) so consumers stop hard-coding it. The landing page calls
 * WorldOverviewService directly rather than fetching this, but the endpoint is
 * the contract for anything off-origin.
 */
#[Route('/api')]
class WorldOverviewController extends AbstractController
{
    public function __construct(
        private readonly WorldOverviewService $worldOverviewService,
    ) {}

    #[Route('/world/overview', name: 'api_world_overview', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json($this->worldOverviewService->getOverview());
    }
}
