<?php

namespace App\Controller\Api;

use App\Enum\LeaderboardCategory;
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

        $period   = $request->query->getString('period', 'all-time');
        $page     = max(1, $request->query->getInt('page', 1));
        $pageSize = min(100, max(1, $request->query->getInt('pageSize', 20)));

        $dto = $this->calculationService->getLeaderboard($categoryEnum, $period, $page, $pageSize);

        return $this->json($dto->toArray());
    }
}
