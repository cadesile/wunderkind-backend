<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Club;
use App\Entity\User;
use App\Service\ClubResolver;
use App\Service\Competition\MatchQueryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

class MatchController extends AbstractController
{
    public function __construct(
        private readonly ClubResolver $clubResolver,
        private readonly MatchQueryService $matchQueryService,
    ) {}

    #[Route('/api/matches/{matchId}', name: 'api_matches_show', methods: ['GET'])]
    public function show(string $matchId): JsonResponse
    {
        $detail = $this->matchQueryService->findMatchDetail($matchId);
        if ($detail === null) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($detail);
    }

    #[Route('/api/club/matches', name: 'api_club_matches', methods: ['GET'])]
    #[IsGranted('ROLE_CLUB')]
    public function clubMatches(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);
        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        $state  = (string) $request->query->get('state', 'upcoming');
        $limit  = max(1, min(50, (int) $request->query->get('limit', 20)));
        $cursor = $request->query->get('cursor');
        $offset = is_numeric($cursor) ? max(0, (int) $cursor) : 0;

        if ($state === 'recent') {
            $all = $this->matchQueryService->findRecentMatchesForClub($club, $offset + $limit + 1);
        } else {
            $all = $this->matchQueryService->findUpcomingMatchesForClub($club);
        }

        $sliced     = array_slice($all, $offset, $limit + 1);
        $hasMore    = count($sliced) > $limit;
        $matches    = array_slice($sliced, 0, $limit);
        $nextCursor = $hasMore ? (string) ($offset + $limit) : null;

        return $this->json([
            'matches'    => $matches,
            'nextCursor' => $nextCursor,
            'serverTime' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    #[Route('/api/club/h2h/{opponentClubId}', name: 'api_club_h2h', methods: ['GET'])]
    #[IsGranted('ROLE_CLUB')]
    public function headToHead(string $opponentClubId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);
        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!Uuid::isValid($opponentClubId)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->matchQueryService->getHeadToHead($club, $opponentClubId));
    }
}
