<?php

namespace App\Controller\Api;

use App\Dto\Leaderboard\LeaderboardResponseDto;
use App\Enum\LeaderboardCategory;
use App\Service\Leaderboard\LeaderboardPeriod;
use App\Service\LeaderboardCalculationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class LeaderboardController extends AbstractController
{
    public function __construct(
        private readonly LeaderboardCalculationService $calculationService,
    ) {}

    #[Route('/leaderboard/{category}', name: 'api_leaderboard', methods: ['GET'])]
    public function index(string $category, Request $request): JsonResponse
    {
        $categoryEnum = LeaderboardCategory::tryFrom($category);
        if ($categoryEnum === null) {
            $valid = implode(', ', array_column(LeaderboardCategory::cases(), 'value'));
            return $this->json(
                ['error' => "Invalid category. Valid values: {$valid}"],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $period   = $request->query->getString('period', LeaderboardPeriod::ALL_TIME);
        $page     = max(1, $request->query->getInt('page', 1));
        $pageSize = min(100, max(1, $request->query->getInt('pageSize', 20)));

        // ?period= stays permissive — validating it strictly would be a contract change
        // and could 400 a client that today gets an empty 200. So an unrecognised key
        // returns the SAME empty-200 shape it already returns, just without touching the
        // database. Visible behaviour is identical; what disappears is the ability for any
        // string on the internet to mint a cache key and trigger a recalculate() *write*
        // (getLeaderboard()'s cold callback recalculates, and computeAggregateScores()
        // will happily write rows for any period string at all).
        if (!LeaderboardPeriod::isValidKey($period)) {
            return $this->json(new LeaderboardResponseDto(
                category: $categoryEnum->value,
                period: $period,
                entries: [],
                total: 0,
                page: $page,
                pageSize: $pageSize,
                hasNextPage: false,
            )->toArray());
        }

        $dto = $this->calculationService->getLeaderboard($categoryEnum, $period, $page, $pageSize);

        return $this->json($dto->toArray());
    }
}
