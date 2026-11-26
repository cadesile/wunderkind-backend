<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\ClubResolver;
use App\Service\Leaderboard\RankingsSummaryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /api/rankings/summary — the app's Rankings dashboard in one call: every active award
 * (soonest to close first) with the top rows, the requesting club's position and the prizes,
 * plus the player's own award history. See RankingsSummaryService.
 */
#[IsGranted('ROLE_CLUB')]
class RankingsController extends AbstractController
{
    public function __construct(
        private readonly ClubResolver $clubResolver,
        private readonly RankingsSummaryService $summary,
    ) {}

    #[Route('/api/rankings/summary', name: 'api_rankings_summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);
        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->summary->build($club));
    }
}
