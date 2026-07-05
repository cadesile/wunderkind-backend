<?php

namespace App\Controller\Api;

use App\Enum\StatsPeriod;
use App\Service\CommunityStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/stats')]
class CommunityStatsController extends AbstractController
{
    public function __construct(
        private readonly CommunityStatsService $statsService,
    ) {
    }

    #[Route('/most-transfers', name: 'api_stats_most_transfers', methods: ['GET'])]
    public function mostTransfers(Request $request): JsonResponse
    {
        return $this->respond($request, fn (StatsPeriod $p, int $l) => $this->statsService->getMostTransfers($p, $l));
    }

    #[Route('/most-development', name: 'api_stats_most_development', methods: ['GET'])]
    public function mostDevelopment(Request $request): JsonResponse
    {
        return $this->respond($request, fn (StatsPeriod $p, int $l) => $this->statsService->getMostDevelopment($p, $l));
    }

    #[Route('/most-seasons', name: 'api_stats_most_seasons', methods: ['GET'])]
    public function mostSeasons(Request $request): JsonResponse
    {
        return $this->respond($request, fn (StatsPeriod $p, int $l) => $this->statsService->getMostSeasons($p, $l));
    }

    #[Route('/most-trophies', name: 'api_stats_most_trophies', methods: ['GET'])]
    public function mostTrophies(Request $request): JsonResponse
    {
        return $this->respond($request, fn (StatsPeriod $p, int $l) => $this->statsService->getMostTrophies($p, $l));
    }

    private function respond(Request $request, callable $fetch): JsonResponse
    {
        $periodParam = $request->query->getString('period', 'all');
        $period = StatsPeriod::tryFrom($periodParam);

        if ($period === null) {
            $valid = implode(', ', array_column(StatsPeriod::cases(), 'value'));
            return $this->json(['error' => "Invalid period. Valid values: {$valid}"], Response::HTTP_BAD_REQUEST);
        }

        $limit = min(50, max(1, (int) $request->query->get('limit', 10)));

        return $this->json([
            'period'  => $period->value,
            'results' => $fetch($period, $limit),
        ]);
    }
}
