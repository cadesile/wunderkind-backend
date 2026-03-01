<?php

namespace App\Controller;

use App\Entity\LeaderboardEntry;
use App\Entity\User;
use App\Enum\LeaderboardCategory;
use App\Repository\AcademyRepository;
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
        AcademyRepository $academyRepo,
    ): JsonResponse {
        $categoryEnum = LeaderboardCategory::tryFrom($category);
        if ($categoryEnum === null) {
            $valid = implode(', ', array_column(LeaderboardCategory::cases(), 'value'));
            return $this->json(
                ['error' => "Invalid category. Valid values: {$valid}"],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $period  = $request->query->getString('period', 'all-time');
        $entries = $repo->findTopByPeriod($categoryEnum, $period);

        $entriesData = array_map(
            static fn(LeaderboardEntry $e, int $i) => [
                'rank'        => $i + 1,
                'academyName' => $e->getAcademy()->getName(),
                'score'       => $e->getScore(),
            ],
            $entries,
            array_keys($entries),
        );

        /** @var User $user */
        $user    = $this->getUser();
        $academy = $academyRepo->findByUser($user);
        $you     = null;

        if ($academy !== null) {
            $myData = $repo->findWithRankForAcademy($academy, $categoryEnum, $period);
            if ($myData !== null) {
                $you = [
                    'rank'        => $myData['rank'],
                    'academyName' => $myData['entry']->getAcademy()->getName(),
                    'score'       => $myData['entry']->getScore(),
                ];
            }
        }

        return $this->json([
            'category' => $categoryEnum->value,
            'period'   => $period,
            'entries'  => $entriesData,
            'you'      => $you,
        ]);
    }
}
