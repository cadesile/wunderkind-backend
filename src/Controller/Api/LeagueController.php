<?php

namespace App\Controller\Api;

use App\Dto\ConcludeSeasonRequest;
use App\Entity\User;
use App\Repository\AcademyRepository;
use App\Repository\SeasonSnapshotRepository;
use App\Service\LeagueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/league')]
#[IsGranted('ROLE_ACADEMY')]
class LeagueController extends AbstractController
{
    public function __construct(
        private readonly AcademyRepository        $academyRepository,
        private readonly LeagueService            $leagueService,
        private readonly SeasonSnapshotRepository $seasonSnapshotRepository,
    ) {}

    #[Route('/conclude-season', name: 'api_league_conclude_season', methods: ['POST'])]
    public function concludeSeason(#[MapRequestPayload] ConcludeSeasonRequest $dto): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized.'], 401);
        }

        $academy = $this->academyRepository->findByUser($user);
        if ($academy === null) {
            return $this->json(['error' => 'Academy not found.'], 404);
        }

        try {
            $result = $this->leagueService->concludeSeason($academy, $dto);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        return $this->json($result);
    }

    #[Route('/season-history', name: 'api_league_season_history', methods: ['GET'])]
    public function seasonHistory(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized.'], 401);
        }

        $academy = $this->academyRepository->findByUser($user);
        if ($academy === null) {
            return $this->json(['error' => 'Academy not found.'], 404);
        }

        $snapshots = $this->seasonSnapshotRepository->findByAcademy($academy);

        return $this->json([
            'seasons' => array_map(fn($s) => [
                'id'            => (string) $s->getId(),
                'season'        => $s->getSeason(),
                'leagueTier'    => $s->getSnapshotData()['amp']['leagueTier'] ?? null,
                'leagueName'    => $s->getSnapshotData()['amp']['leagueName'] ?? null,
                'finalPosition' => $s->getSnapshotData()['amp']['finalPosition'] ?? null,
                'points'        => $s->getSnapshotData()['amp']['points'] ?? null,
                'promoted'      => $s->getSnapshotData()['amp']['promoted'] ?? null,
                'relegated'     => $s->getSnapshotData()['amp']['relegated'] ?? null,
            ], $snapshots),
        ]);
    }

    #[Route('/season-history/{season}', name: 'api_league_season_history_detail', methods: ['GET'])]
    public function seasonHistoryDetail(int $season): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized.'], 401);
        }

        $academy = $this->academyRepository->findByUser($user);
        if ($academy === null) {
            return $this->json(['error' => 'Academy not found.'], 404);
        }

        $snapshot = $this->seasonSnapshotRepository->findByAcademyAndSeason($academy, $season);
        if ($snapshot === null) {
            return $this->json(['error' => 'Season not found.'], 404);
        }

        return $this->json([
            'id'           => (string) $snapshot->getId(),
            'season'       => $snapshot->getSeason(),
            'country'      => $snapshot->getCountry(),
            'snapshotData' => $snapshot->getSnapshotData(),
        ]);
    }
}
