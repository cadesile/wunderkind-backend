<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Club;
use App\Entity\Friendly\FriendlyChallenge;
use App\Entity\User;
use App\Enum\Tier;
use App\Exception\ClubIdRequiredException;
use App\Repository\Friendly\FriendlyChallengeRepository;
use App\Service\ClubResolver;
use App\Service\Competition\MatchQueryService;
use App\Service\Competition\SnapshotValidator;
use App\Service\Friendly\FriendlyChallengeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

class FriendlyController extends AbstractController
{
    public function __construct(
        private readonly ClubResolver $clubResolver,
        private readonly FriendlyChallengeRepository $repository,
        private readonly FriendlyChallengeService $service,
        private readonly SnapshotValidator $snapshotValidator,
        private readonly MatchQueryService $matchQueryService,
    ) {}

    #[Route('/api/friendlies/challenges', name: 'api_friendlies_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $club          = $this->resolveOptionalClub();
        $now           = new \DateTimeImmutable();
        $stakeBand     = $request->query->get('stake');
        $reputationRaw = $request->query->get('reputation');
        $limit         = max(1, min(50, (int) $request->query->get('limit', 20)));
        $cursor        = $request->query->get('cursor');
        $offset        = is_numeric($cursor) ? max(0, (int) $cursor) : 0;

        $reputationTiers = null;
        if (is_string($reputationRaw) && trim($reputationRaw) !== '') {
            $reputationTiers = [];
            foreach (explode(',', $reputationRaw) as $part) {
                $tier = Tier::tryFrom(strtolower(trim($part)));
                if ($tier !== null) {
                    $reputationTiers[] = $tier->value;
                }
            }
        }

        $excludeUserId = $club !== null ? (string) $club->getUser()->getId() : null;
        $rows          = $this->repository->findOpenBoard(
            now: $now,
            stakeBand: is_string($stakeBand) ? $stakeBand : null,
            reputationTiers: $reputationTiers,
            excludeUserId: $excludeUserId,
            offset: $offset,
            limit: $limit + 1,
        );

        $hasMore    = count($rows) > $limit;
        $pageRows   = array_slice($rows, 0, $limit);
        $nextCursor = $hasMore ? (string) ($offset + $limit) : null;

        return $this->json([
            'challenges' => array_map(fn (FriendlyChallenge $c) => $this->service->serializeChallengeItem($c, $club), $pageRows),
            'nextCursor' => $nextCursor,
            'serverTime' => $now->format(DATE_ATOM),
        ]);
    }

    #[Route('/api/club/friendlies/challenges', name: 'api_club_friendlies_list', methods: ['GET'])]
    #[IsGranted('ROLE_CLUB')]
    public function myChallenges(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);
        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        $now      = new \DateTimeImmutable();
        $open     = $this->repository->findOpenForClub($club, $now);
        $accepted = $this->repository->findAcceptedForClub($club);

        return $this->json([
            'open'       => array_map(fn (FriendlyChallenge $c) => $this->service->serializeChallengeItem($c, $club), $open),
            'accepted'   => array_map(fn (FriendlyChallenge $c) => $this->service->serializeChallengeItem($c, $club), $accepted),
            'serverTime' => $now->format(DATE_ATOM),
        ]);
    }

    #[Route('/api/friendlies/challenges', name: 'api_friendlies_post', methods: ['POST'])]
    #[IsGranted('ROLE_CLUB')]
    public function postChallenge(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'validation_failed', 'message' => 'Invalid JSON payload.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $snapshot = is_array($body['snapshot'] ?? null) ? $body['snapshot'] : $body;
        $club     = $this->resolveClubForWrite($user, $request, is_string($snapshot['club']['id'] ?? null) ? $snapshot['club']['id'] : null);
        if ($club instanceof JsonResponse) {
            return $club;
        }

        $venue          = (string) ($body['venue'] ?? '');
        $expiresInHours = isset($body['expiresInHours']) && is_int($body['expiresInHours']) ? $body['expiresInHours'] : (int) ($body['expiresInHours'] ?? 0);
        $stakePence     = isset($body['stakePence']) && is_numeric($body['stakePence']) ? (int) $body['stakePence'] : -1;

        if (!in_array($venue, FriendlyChallengeService::ALLOWED_VENUES, true)) {
            return $this->json(['error' => 'validation_failed', 'field' => 'venue', 'message' => 'venue must be "home" or "away".'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!in_array($expiresInHours, FriendlyChallengeService::ALLOWED_EXPIRES_IN_HOURS, true)) {
            return $this->json(['error' => 'validation_failed', 'field' => 'expiresInHours', 'message' => 'expiresInHours must be 1, 6, or 24.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!in_array($stakePence, FriendlyChallengeService::ALLOWED_STAKE_PENCE, true)) {
            return $this->json(['error' => 'validation_failed', 'field' => 'stakePence', 'message' => 'Unsupported stakePence amount.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $clubArr       = is_array($snapshot['club'] ?? null) ? $snapshot['club'] : [];
        $playersArr    = is_array($snapshot['players'] ?? null) ? $snapshot['players'] : [];
        $staffArr      = is_array($snapshot['staff'] ?? null) ? $snapshot['staff'] : [];
        $facilitiesArr = is_array($snapshot['facilities'] ?? null) ? $snapshot['facilities'] : [];

        $violations = $this->snapshotValidator->validate($clubArr, $playersArr, $staffArr, (string) $club->getId());
        if ($violations !== []) {
            return $this->json(['error' => 'invalid_snapshot', 'violations' => $violations], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $snapshotPayload = [
            'club'             => $clubArr,
            'players'          => $playersArr,
            'staff'            => $staffArr,
            'facilities'       => $facilitiesArr,
            'clientSnapshotAt' => is_string($snapshot['clientSnapshotAt'] ?? null) ? $snapshot['clientSnapshotAt'] : null,
        ];

        try {
            $challenge = $this->service->postChallenge($club, $venue, $stakePence, $expiresInHours, $snapshotPayload);
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'challenge_limit_reached' => $this->json([
                    'error'   => 'challenge_limit_reached',
                    'message' => 'You already have 3 open challenges.',
                ], Response::HTTP_CONFLICT),
                'insufficient_funds' => $this->json([
                    'error'    => 'insufficient_funds',
                    'required' => $stakePence,
                    'actual'   => $club->getBalance(),
                ], Response::HTTP_FORBIDDEN),
                default => throw $e,
            };
        }

        return $this->json([
            'challenge' => $this->service->serializeChallengeItem($challenge, $club),
            'club'      => [
                'clubId'  => (string) $club->getId(),
                'balance' => $club->getBalance(),
            ],
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/friendlies/challenges/{id}/cancel', name: 'api_friendlies_cancel', methods: ['POST'])]
    #[IsGranted('ROLE_CLUB')]
    public function cancel(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->resolveClubForWrite($user, $request);
        if ($club instanceof JsonResponse) {
            return $club;
        }

        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $challenge = $this->repository->find($id);
        if ($challenge === null) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->service->cancelChallenge($challenge, $club);
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'not_challenger'    => $this->json(['error' => 'not_challenger'], Response::HTTP_FORBIDDEN),
                'challenge_taken'   => $this->json(['error' => 'challenge_taken'], Response::HTTP_CONFLICT),
                'challenge_expired' => $this->json(['error' => 'challenge_expired'], Response::HTTP_CONFLICT),
                default             => throw $e,
            };
        }

        return $this->json([
            'status' => 'cancelled',
            'club'   => [
                'clubId'  => (string) $club->getId(),
                'balance' => $club->getBalance(),
            ],
        ]);
    }

    #[Route('/api/friendlies/challenges/{id}/accept', name: 'api_friendlies_accept', methods: ['POST'])]
    #[IsGranted('ROLE_CLUB')]
    public function accept(string $id, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'validation_failed', 'message' => 'Invalid JSON payload.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $snapshot = is_array($body['snapshot'] ?? null) ? $body['snapshot'] : $body;
        $club     = $this->resolveClubForWrite($user, $request, is_string($snapshot['club']['id'] ?? null) ? $snapshot['club']['id'] : null);
        if ($club instanceof JsonResponse) {
            return $club;
        }

        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $challenge = $this->repository->find($id);
        if ($challenge === null) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $clubArr       = is_array($snapshot['club'] ?? null) ? $snapshot['club'] : [];
        $playersArr    = is_array($snapshot['players'] ?? null) ? $snapshot['players'] : [];
        $staffArr      = is_array($snapshot['staff'] ?? null) ? $snapshot['staff'] : [];
        $facilitiesArr = is_array($snapshot['facilities'] ?? null) ? $snapshot['facilities'] : [];

        $violations = $this->snapshotValidator->validate($clubArr, $playersArr, $staffArr, (string) $club->getId());
        if ($violations !== []) {
            return $this->json(['error' => 'invalid_snapshot', 'violations' => $violations], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $snapshotPayload = [
            'club'             => $clubArr,
            'players'          => $playersArr,
            'staff'            => $staffArr,
            'facilities'       => $facilitiesArr,
            'clientSnapshotAt' => is_string($snapshot['clientSnapshotAt'] ?? null) ? $snapshot['clientSnapshotAt'] : null,
        ];

        try {
            $accepted = $this->service->acceptChallenge($challenge, $club, $snapshotPayload);
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'own_challenge'      => $this->json(['error' => 'own_challenge'], Response::HTTP_CONFLICT),
                'same_owner'         => $this->json(['error' => 'same_owner'], Response::HTTP_FORBIDDEN),
                'challenge_taken'    => $this->json(['error' => 'challenge_taken'], Response::HTTP_CONFLICT),
                'challenge_expired'  => $this->json(['error' => 'challenge_expired'], Response::HTTP_CONFLICT),
                'insufficient_funds' => $this->json([
                    'error'    => 'insufficient_funds',
                    'required' => $challenge->getStakePence(),
                    'actual'   => $club->getBalance(),
                ], Response::HTTP_FORBIDDEN),
                default => throw $e,
            };
        }

        return $this->json([
            'match' => $this->matchQueryService->serializeFriendlyMatchSummary($accepted, $club),
            'club'  => [
                'clubId'  => (string) $club->getId(),
                'balance' => $club->getBalance(),
            ],
        ]);
    }

    private function resolveClubForWrite(User $user, Request $request, ?string $fallbackClubId = null): Club|JsonResponse
    {
        if ($request->headers->has(ClubResolver::CLUB_ID_HEADER)) {
            $rawHeader = trim((string) $request->headers->get(ClubResolver::CLUB_ID_HEADER));
            if ($rawHeader === '') {
                return $this->json([
                    'error'   => 'club_id_required',
                    'message' => 'The X-Club-Id header is required for friendly write actions.',
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            return $this->clubResolver->resolveRequiredFromRequest($user, $fallbackClubId);
        } catch (ClubIdRequiredException) {
            return $this->json([
                'error'   => 'club_id_required',
                'message' => 'The X-Club-Id header is required for friendly write actions.',
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    private function resolveOptionalClub(): ?Club
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }

        return $this->clubResolver->resolveFromRequest($user);
    }
}
