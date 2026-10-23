<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Friendly\FriendlyChallenge;
use App\Entity\User;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Repository\Competition\ActiveCompetitionRepository;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionTemplateRepository;
use App\Repository\Friendly\FriendlyChallengeRepository;
use App\Repository\InboxMessageRepository;
use App\Service\ClubResolver;
use App\Service\Competition\MatchQueryService;
use App\Service\Friendly\FriendlyChallengeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CLUB')]
class ClubHubController extends AbstractController
{
    public function __construct(
        private readonly ClubResolver $clubResolver,
        private readonly CompetitionController $competitionController,
        private readonly InboxController $inboxController,
        private readonly CompetitionTemplateRepository $templateRepository,
        private readonly ActiveCompetitionRepository $activeCompetitionRepository,
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly FriendlyChallengeRepository $friendlyRepository,
        private readonly FriendlyChallengeService $friendlyService,
        private readonly MatchQueryService $matchQueryService,
        private readonly InboxMessageRepository $inboxMessageRepository,
    ) {}

    #[Route('/api/club/competitions', name: 'api_club_competitions', methods: ['GET'])]
    public function myCompetitions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);
        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        $statusParam = $request->query->get('status');
        $limit       = max(1, min(50, (int) $request->query->get('limit', 20)));
        $cursor      = $request->query->get('cursor');
        $offset      = is_numeric($cursor) ? max(0, (int) $cursor) : 0;

        $allowedStatuses = null;
        if (is_string($statusParam) && trim($statusParam) !== '') {
            $allowedStatuses = [];
            foreach (explode(',', $statusParam) as $part) {
                $s = ActiveCompetitionStatus::tryFrom(trim($part));
                if ($s !== null) {
                    $allowedStatuses[] = $s;
                }
            }
        }

        $entrants  = $this->entrantRepository->findByClub($club, includeWithdrawn: false);
        $instances = [];
        foreach ($entrants as $entrant) {
            if ($entrant->getStatus() === CompetitionEntrantStatus::WITHDRAWN) {
                continue;
            }
            $comp = $entrant->getActiveCompetition();
            if ($allowedStatuses !== null && $allowedStatuses !== [] && !in_array($comp->getStatus(), $allowedStatuses, true)) {
                continue;
            }
            $instances[] = $comp;
        }

        $sliced     = array_slice($instances, $offset, $limit + 1);
        $hasMore    = count($sliced) > $limit;
        $pageRows   = array_slice($sliced, 0, $limit);
        $nextCursor = $hasMore ? (string) ($offset + $limit) : null;

        return $this->json([
            'competitions' => array_map(fn (ActiveCompetition $a) => $this->competitionController->serializeInstanceSummary($a, $club), $pageRows),
            'nextCursor'   => $nextCursor,
            'serverTime'   => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    #[Route('/api/club/hub', name: 'api_club_hub', methods: ['GET'])]
    public function hub(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);
        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        $now       = new \DateTimeImmutable();
        $upcoming  = $this->matchQueryService->findUpcomingMatchesForClub($club);
        $recent    = $this->matchQueryService->findRecentMatchesForClub($club, 5);
        $nextMatch = null;
        foreach ($upcoming as $item) {
            if ($item['status'] === 'scheduled') {
                $nextMatch = $item;
                break;
            }
        }
        $nextMatch ??= ($upcoming[0] ?? null);

        $inboxMessages = $this->inboxMessageRepository->findByClub($club, 10);
        $unreadCount   = $this->inboxMessageRepository->countUnread($club);

        $openChallenges     = $this->friendlyRepository->findOpenForClub($club, $now);
        $acceptedChallenges = $this->friendlyRepository->findAcceptedForClub($club);
        $boardChallenges    = $this->friendlyRepository->findOpenBoard(
            now: $now,
            excludeUserId: (string) $club->getUser()->getId(),
            offset: 0,
            limit: 5,
        );

        $openCompetitions = [];
        $champions        = [];
        foreach ($this->templateRepository->findAllActive() as $template) {
            $openInstance = $this->activeCompetitionRepository->findOpenForTemplate($template);
            if ($openInstance !== null) {
                $openCompetitions[] = $this->competitionController->serializeInstanceSummary($openInstance, $club);
            }

            $latestCompleted = $this->activeCompetitionRepository->findLatestCompletedForTemplate($template);
            if ($latestCompleted !== null) {
                $summary     = $this->competitionController->serializeInstanceSummary($latestCompleted, null);
                $champions[] = [
                    'templateId'      => (string) $template->getId(),
                    'templateName'    => $template->getName(),
                    'entrantCapacity' => $latestCompleted->getEntrantCapacity(),
                    'trophy'          => $summary['trophy'],
                    'instanceId'      => (string) $latestCompleted->getId(),
                    'completedAt'     => $latestCompleted->getCompletedAt()?->format(DATE_ATOM),
                    'champion'        => $summary['champion'],
                    'runnerUp'        => $summary['runnerUp'],
                ];
            }
        }

        return $this->json([
            'nextMatch'    => $nextMatch,
            'inbox'        => [
                'unreadCount' => $unreadCount,
                'messages'    => array_map(fn ($m) => $this->inboxController->serializeMessage($m), $inboxMessages),
            ],
            'myChallenges' => [
                'open'     => array_map(fn (FriendlyChallenge $c) => $this->friendlyService->serializeChallengeItem($c, $club), $openChallenges),
                'accepted' => array_map(fn (FriendlyChallenge $c) => $this->friendlyService->serializeChallengeItem($c, $club), $acceptedChallenges),
            ],
            'topChallenges' => array_map(fn (FriendlyChallenge $c) => $this->friendlyService->serializeChallengeItem($c, $club), $boardChallenges),
            'upcoming'      => array_slice($upcoming, 0, 5),
            'recent'        => $recent,
            'open'          => $openCompetitions,
            'champions'     => $champions,
            'serverTime'    => $now->format(DATE_ATOM),
        ]);
    }
}
