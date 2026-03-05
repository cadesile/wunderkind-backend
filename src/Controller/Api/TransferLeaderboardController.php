<?php

namespace App\Controller\Api;

use App\Service\TransferLeaderboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/leaderboard/transfers')]
class TransferLeaderboardController extends AbstractController
{
    private const VALID_PERIODS = ['week', 'month', 'all-time'];

    public function __construct(
        private readonly TransferLeaderboardService $leaderboardService,
    ) {}

    #[Route('/top-sellers', name: 'api_transfer_leaderboard_top_sellers', methods: ['GET'])]
    public function topSellers(Request $request): JsonResponse
    {
        $period = $request->query->get('period', 'week');
        $limit  = min((int) $request->query->get('limit', 10), 50);

        if (!in_array($period, self::VALID_PERIODS, true)) {
            return $this->json(['error' => 'Invalid period. Must be: week, month, or all-time'], 400);
        }

        $topSellers = $this->leaderboardService->getTopSellers($period, $limit);

        return $this->json([
            'period'     => $period,
            'topSellers' => array_map(fn($s) => [
                'academyName'   => $s['academyName'],
                'totalSales'    => (int) $s['totalSales'],
                'transferCount' => (int) $s['transferCount'],
                'averageSale'   => (int) $s['transferCount'] > 0
                    ? (int) ((int) $s['totalSales'] / (int) $s['transferCount'])
                    : 0,
            ], $topSellers),
        ]);
    }

    #[Route('/most-valuable', name: 'api_transfer_leaderboard_most_valuable', methods: ['GET'])]
    public function mostValuable(Request $request): JsonResponse
    {
        $period = $request->query->get('period', 'week');

        if (!in_array($period, self::VALID_PERIODS, true)) {
            return $this->json(['error' => 'Invalid period. Must be: week, month, or all-time'], 400);
        }

        $sale = $this->leaderboardService->getMostValuableSale($period);

        if (!$sale) {
            return $this->json([
                'period'  => $period,
                'sale'    => null,
                'message' => 'No sales recorded for this period',
            ]);
        }

        return $this->json([
            'period' => $period,
            'sale'   => $sale,
        ]);
    }
}
