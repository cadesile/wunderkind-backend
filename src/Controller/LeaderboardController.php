<?php

namespace App\Controller;

use App\Entity\LeaderboardEntry;
use App\Enum\LeaderboardCategory;
use App\Repository\LeaderboardEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class LeaderboardController extends AbstractController
{
    #[Route('/leaderboard/{category}', name: 'api_leaderboard', methods: ['GET'])]
    public function index(
        string $category,
        Request $request,
        LeaderboardEntryRepository $repo,
    ): JsonResponse {
        $categoryEnum = LeaderboardCategory::tryFrom($category);
        if ($categoryEnum === null) {
            $valid = implode(', ', array_column(LeaderboardCategory::cases(), 'value'));
            return $this->json(
                ['error' => "Invalid category. Valid values: {$valid}"],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $period   = $request->query->getString('period', 'all-time');
        $page     = max(1, (int) $request->query->get('page', 1));
        $pageSize = min(100, max(1, (int) $request->query->get('pageSize', 20)));

        $all     = $repo->findTopByPeriod($categoryEnum, $period, 1000);
        $total   = count($all);
        $offset  = ($page - 1) * $pageSize;
        $paged   = array_slice($all, $offset, $pageSize);

        $entriesData = array_map(
            static function (LeaderboardEntry $e, int $i) use ($offset): array {
                $academy = $e->getAcademy();
                return [
                    'rank'                => $offset + $i + 1,
                    'academyName'         => $academy->getName(),
                    'reputation'          => $academy->getReputation(),
                    'totalCareerEarnings' => $academy->getTotalCareerEarnings(),
                    'weekNumber'          => $academy->getLastSyncedWeek(),
                ];
            },
            $paged,
            array_keys($paged),
        );

        return $this->json([
            'entries'     => array_values($entriesData),
            'total'       => $total,
            'page'        => $page,
            'pageSize'    => $pageSize,
            'hasNextPage' => ($offset + $pageSize) < $total,
        ]);
    }
}
