<?php

namespace App\Controller\Api;

use App\Dto\ConcludeSeasonRequest;
use App\Entity\User;
use App\Repository\ClubRepository;
use App\Repository\SeasonRecordRepository;
use App\Repository\SeasonSnapshotRepository;
use App\Service\LeagueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/league')]
#[IsGranted('ROLE_CLUB')]
class LeagueController extends AbstractController
{
    public function __construct(
        private readonly ClubRepository        $clubRepository,
        private readonly LeagueService            $leagueService,
        private readonly SeasonSnapshotRepository $seasonSnapshotRepository,
        private readonly SeasonRecordRepository   $seasonRecordRepository,
    ) {}

    #[Route('/conclude-season', name: 'api_league_conclude_season', methods: ['POST'])]
    public function concludeSeason(#[MapRequestPayload] ConcludeSeasonRequest $dto): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized.'], 401);
        }

        $club = $this->clubRepository->findByUser($user);
        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], 404);
        }

        try {
            $result = $this->leagueService->concludeSeason($club, $dto);
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

        $club = $this->clubRepository->findByUser($user);
        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], 404);
        }

        $records = $this->seasonRecordRepository->findByClub($club);

        return $this->json([
            'seasons' => array_map(fn($r) => [
                'id'            => (string) $r->getId(),
                'season'        => $r->getSeason(),
                'leagueTier'    => $r->getLeague()->getTier(),
                'leagueName'    => $r->getLeague()->getName(),
                'finalPosition' => $r->getFinalPosition(),
                'points'        => $r->getPoints(),
                'promoted'      => $r->isPromoted(),
                'relegated'     => $r->isRelegated(),
            ], $records),
        ]);
    }

    #[Route('/season-history/{season}', name: 'api_league_season_history_detail', methods: ['GET'])]
    public function seasonHistoryDetail(int $season): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized.'], 401);
        }

        $club = $this->clubRepository->findByUser($user);
        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], 404);
        }

        $snapshot = $this->seasonSnapshotRepository->findByClubAndSeason($club, $season);
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
