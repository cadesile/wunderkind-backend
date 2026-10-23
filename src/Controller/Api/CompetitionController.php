<?php

namespace App\Controller\Api;

use App\Dto\Competition\CompetitionRegisterRequest;
use App\Dto\Competition\CompetitionResubmitRequest;
use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionResult;
use App\Entity\Competition\CompetitionRound;
use App\Entity\Competition\CompetitionTemplate;
use App\Entity\Competition\RewardTemplate;
use App\Entity\User;
use App\Enum\Appearance\TrophyFinish;
use App\Enum\Appearance\TrophyStyle;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\CompetitionFixtureStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Enum\Tier;
use App\Exception\ClubIdRequiredException;
use App\Repository\Competition\ActiveCompetitionRepository;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionResultRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Repository\Competition\CompetitionTemplateRepository;
use App\Service\ClubInitializationService;
use App\Service\ClubResolver;
use App\Service\Competition\BracketLabeler;
use App\Service\Competition\CompetitionRegistrationService;
use App\Service\Competition\EligibilityEvaluator;
use App\Service\Competition\SnapshotValidator;
use App\Service\Notification\PushNotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * `available`, `active`, `champions`, ``, and `{id}` are PUBLIC (security.yaml access_control)
 * so the web landing page can hit them directly — no class-level IsGranted here,
 * register/withdraw/resubmit carry it per-action instead.
 */
#[Route('/api/competitions')]
class CompetitionController extends AbstractController
{
    public function __construct(
        private readonly ClubResolver $clubResolver,
        private readonly CompetitionTemplateRepository $templateRepository,
        private readonly ActiveCompetitionRepository $activeCompetitionRepository,
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly CompetitionFixtureRepository $fixtureRepository,
        private readonly CompetitionResultRepository $resultRepository,
        private readonly EligibilityEvaluator $eligibilityEvaluator,
        private readonly SnapshotValidator $snapshotValidator,
        private readonly CompetitionRegistrationService $registrationService,
        private readonly PushNotificationService $pushNotificationService,
    ) {}

    #[Route('', name: 'api_competitions_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $club = $this->resolveOptionalClub();

        $statusParam   = $request->query->get('status');
        $capacityParam = $request->query->get('capacity');
        $templateId    = $request->query->get('templateId');
        $sort          = (string) $request->query->get('sort', 'startsAt');
        $limit         = max(1, min(50, (int) $request->query->get('limit', 20)));
        $cursor        = $request->query->get('cursor');
        $offset        = is_numeric($cursor) ? max(0, (int) $cursor) : 0;

        $statuses = null;
        if (is_string($statusParam) && trim($statusParam) !== '') {
            $statuses = [];
            foreach (explode(',', $statusParam) as $part) {
                $status = ActiveCompetitionStatus::tryFrom(trim($part));
                if ($status !== null) {
                    $statuses[] = $status;
                }
            }
        }

        $capacities = null;
        if (is_string($capacityParam) && trim($capacityParam) !== '') {
            $capacities = array_values(array_filter(array_map('intval', explode(',', $capacityParam))));
        }

        $rows = $this->activeCompetitionRepository->findFiltered(
            statuses: $statuses,
            capacities: $capacities,
            templateId: is_string($templateId) ? $templateId : null,
            sort: $sort,
            offset: $offset,
            limit: $limit + 1,
        );

        $hasMore    = count($rows) > $limit;
        $pageRows   = array_slice($rows, 0, $limit);
        $nextCursor = $hasMore ? (string) ($offset + $limit) : null;

        return $this->json([
            'competitions' => array_map(fn (ActiveCompetition $a) => $this->serializeInstanceSummary($a, $club), $pageRows),
            'nextCursor'   => $nextCursor,
            'serverTime'   => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    #[Route('/champions', name: 'api_competitions_champions', methods: ['GET'])]
    public function champions(): JsonResponse
    {
        $champions = [];
        foreach ($this->templateRepository->findAllActive() as $template) {
            $latest = $this->activeCompetitionRepository->findLatestCompletedForTemplate($template);
            if ($latest === null) {
                continue;
            }
            $summary     = $this->serializeInstanceSummary($latest, null);
            $champions[] = [
                'templateId'      => (string) $template->getId(),
                'templateName'    => $template->getName(),
                'entrantCapacity' => $latest->getEntrantCapacity(),
                'trophy'          => $this->normalizeTrophy($template->getTrophy()),
                'instanceId'      => (string) $latest->getId(),
                'completedAt'     => $latest->getCompletedAt()?->format(DATE_ATOM),
                'champion'        => $summary['champion'],
                'runnerUp'        => $summary['runnerUp'],
            ];
        }

        return $this->json([
            'champions'  => $champions,
            'serverTime' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    #[Route('/available', name: 'api_competitions_available', methods: ['GET'])]
    public function available(): JsonResponse
    {
        $club = $this->resolveOptionalClub();

        $open = [];
        foreach ($this->templateRepository->findAllActive() as $template) {
            $instance = $this->activeCompetitionRepository->findOpenForTemplate($template);
            if ($instance === null) {
                continue;
            }

            $row             = $this->serializeInstanceSummary($instance, $club);
            $row['entrants'] = array_map(
                $this->serializeEntrantPreview(...),
                $this->entrantRepository->findByCompetitionOrderedByRegistration($instance),
            );

            if ($club !== null) {
                $existing                 = $this->entrantRepository->findByCompetitionAndClub($instance, $club);
                $row['alreadyRegistered'] = $existing !== null && $existing->getStatus() !== CompetitionEntrantStatus::WITHDRAWN;
                $eligibility              = $this->eligibilityEvaluator->evaluate($template, $club);
                $row['eligibility']       = ['eligible' => $eligibility->eligible, 'reasons' => $eligibility->reasons];
            }

            $open[] = $row;
        }

        return $this->json([
            'open'              => $open,
            'running'           => array_map(fn (ActiveCompetition $a) => $this->serializeInstanceSummary($a, $club), $this->activeCompetitionRepository->findByStatus(ActiveCompetitionStatus::RUNNING)),
            'recentlyCompleted' => array_map(fn (ActiveCompetition $a) => $this->serializeInstanceSummary($a, $club), $this->activeCompetitionRepository->findRecentlyCompleted(10)),
            'serverTime'        => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    /** Locked-full, not-yet-completed instances — same per-item shape as available()'s running/recentlyCompleted rows. */
    #[Route('/active', name: 'api_competitions_active', methods: ['GET'])]
    public function active(): JsonResponse
    {
        $club = $this->resolveOptionalClub();

        return $this->json([
            'active'     => array_map(fn (ActiveCompetition $a) => $this->serializeInstanceSummary($a, $club), $this->activeCompetitionRepository->findActive()),
            'serverTime' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    #[Route('/{id}/register', name: 'api_competitions_register', methods: ['POST'])]
    #[IsGranted('ROLE_CLUB')]
    public function register(string $id, Request $request, #[MapRequestPayload] CompetitionRegisterRequest $dto): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->resolveClubForWrite($user, $request, is_string($dto->club['id'] ?? null) ? $dto->club['id'] : null);
        if ($club instanceof JsonResponse) {
            return $club;
        }

        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $activeCompetition = $this->activeCompetitionRepository->find($id);
        if ($activeCompetition === null) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        if ($activeCompetition->getStatus() !== ActiveCompetitionStatus::REGISTERING) {
            return $this->json(['error' => 'registration_closed'], Response::HTTP_CONFLICT);
        }

        $template    = $activeCompetition->getTemplate();
        $eligibility = $this->eligibilityEvaluator->evaluate($template, $club);
        if (!$eligibility->eligible) {
            return $this->json([
                'error'             => 'not_eligible',
                'reasons'           => $eligibility->reasons,
                'structuredReasons' => $eligibility->structuredReasons,
            ], Response::HTTP_FORBIDDEN);
        }

        $violations = $this->snapshotValidator->validate($dto->club, $dto->players, $dto->staff, (string) $club->getId());
        if ($violations !== []) {
            return $this->json(['error' => 'invalid_snapshot', 'violations' => $violations], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->registrationService->register(
                $activeCompetition,
                $club,
                $this->buildSnapshotArray($dto->club, $dto->players, $dto->staff, $dto->facilities, $dto->clientSnapshotAt),
            );
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'registration_closed' => $this->json(['error' => 'registration_closed'], Response::HTTP_CONFLICT),
                'competition_full'    => $this->json(['error' => 'competition_full'], Response::HTTP_CONFLICT),
                'insufficient_funds'  => $this->json([
                    'error'    => 'insufficient_funds',
                    'required' => $template->getEntryFeePence(),
                    'actual'   => $club->getBalance(),
                ], Response::HTTP_FORBIDDEN),
                default => throw $e,
            };
        }

        $entrant = $result['entrant'];

        if ($result['wasNewRegistration']) {
            $existingEntrantUserIds = array_values(array_filter(array_map(
                static fn ($existing) => $existing->getId()->equals($entrant->getId()) ? null : (string) $existing->getClub()->getUser()->getId(),
                $this->entrantRepository->findByCompetitionOrderedByRegistration($activeCompetition),
            )));

            $this->pushNotificationService->notifyUsers(
                $existingEntrantUserIds,
                'New challenger!',
                sprintf('%s just joined your competition.', $club->getName()),
                ['type' => 'NEW_REGISTRANT', 'competitionId' => (string) $activeCompetition->getId()],
            );
        }

        return $this->json([
            'entrantId'   => (string) $entrant->getId(),
            'instanceId'  => (string) $activeCompetition->getId(),
            'seed'        => $entrant->getSeed(),
            'status'      => $entrant->getStatus()->value,
            'entrant'     => $this->serializeEntrantPreview($entrant),
            'competition' => $this->serializeInstanceSummary($activeCompetition, $club),
            'club'        => [
                'clubId'  => (string) $club->getId(),
                'balance' => $club->getBalance(),
            ],
        ], $result['wasNewRegistration'] ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/{id}/withdraw', name: 'api_competitions_withdraw', methods: ['POST'])]
    #[IsGranted('ROLE_CLUB')]
    public function withdraw(string $id, Request $request): JsonResponse
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

        $activeCompetition = $this->activeCompetitionRepository->find($id);
        if ($activeCompetition === null) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->registrationService->withdraw($activeCompetition, $club);
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'withdrawal_closed' => $this->json(['error' => 'withdrawal_closed'], Response::HTTP_CONFLICT),
                'not_registered'    => $this->json(['error' => 'not_registered'], Response::HTTP_NOT_FOUND),
                default             => throw $e,
            };
        }

        return $this->json([
            'status'      => 'withdrawn',
            'competition' => $this->serializeInstanceSummary($activeCompetition, $club),
            'club'        => [
                'clubId'  => (string) $club->getId(),
                'balance' => $club->getBalance(),
            ],
        ]);
    }

    #[Route('/{id}/resubmit', name: 'api_competitions_resubmit', methods: ['POST'])]
    #[IsGranted('ROLE_CLUB')]
    public function resubmit(string $id, Request $request, #[MapRequestPayload] CompetitionResubmitRequest $dto): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->resolveClubForWrite($user, $request, $dto->clubId ?? (is_string($dto->club['id'] ?? null) ? $dto->club['id'] : null));
        if ($club instanceof JsonResponse) {
            return $club;
        }

        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $activeCompetition = $this->activeCompetitionRepository->find($id);
        if ($activeCompetition === null) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $entrant = $this->entrantRepository->findByCompetitionAndClub($activeCompetition, $club);
        if ($entrant === null || $entrant->getStatus() === CompetitionEntrantStatus::WITHDRAWN) {
            return $this->json(['error' => 'not_registered'], Response::HTTP_NOT_FOUND);
        }

        $stillInPlay = in_array($entrant->getStatus(), [CompetitionEntrantStatus::REGISTERED, CompetitionEntrantStatus::ACTIVE], true);
        if (!$stillInPlay || ($activeCompetition->getStatus() !== ActiveCompetitionStatus::REGISTERING && !$this->roundRepository->hasUpcomingRound($activeCompetition))) {
            return $this->json(['error' => 'resubmission_window_closed'], Response::HTTP_CONFLICT);
        }

        if ($dto->clientSnapshotAt !== null) {
            $existingAt = $entrant->getSnapshotJson()['clientSnapshotAt'] ?? null;
            if (is_string($existingAt)) {
                try {
                    $incomingTime = new \DateTimeImmutable($dto->clientSnapshotAt);
                    $existingTime = new \DateTimeImmutable($existingAt);
                    if ($incomingTime < $existingTime) {
                        return $this->json(['error' => 'stale_snapshot'], Response::HTTP_CONFLICT);
                    }
                } catch (\Throwable) {
                    // Ignore malformed client timestamp strings
                }
            }
        }

        $violations = $this->snapshotValidator->validate($dto->club, $dto->players, $dto->staff, (string) $club->getId());
        if ($violations !== []) {
            return $this->json(['error' => 'invalid_snapshot', 'violations' => $violations], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->registrationService->resubmit($entrant, $this->buildSnapshotArray($dto->club, $dto->players, $dto->staff, $dto->facilities, $dto->clientSnapshotAt));

        $currentRound = $this->roundRepository->findCurrentRound($activeCompetition);
        $locksAt      = $currentRound !== null && $currentRound->getStatus() === CompetitionRoundStatus::DRAW_PENDING
            ? $currentRound->getScheduledAt()->format(DATE_ATOM)
            : null;

        return $this->json([
            'entrantId'       => (string) $entrant->getId(),
            'snapshotVersion' => $entrant->getSnapshotVersion() + 1,
            'status'          => 'accepted',
            'locksAt'         => $locksAt,
        ]);
    }

    #[Route('/{id}', name: 'api_competitions_show', methods: ['GET'])]
    public function show(string $id, Request $request): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $activeCompetition = $this->activeCompetitionRepository->find($id);
        if ($activeCompetition === null) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $club   = $this->resolveOptionalClub();
        $etag   = $this->computeEtag($activeCompetition, $club);
        $ifNone = $request->headers->get('If-None-Match');
        if ($ifNone !== null && trim($ifNone) === $etag) {
            return new JsonResponse(null, Response::HTTP_NOT_MODIFIED, ['ETag' => $etag]);
        }

        $roundEntities     = $this->roundRepository->findByCompetitionOrderedByIndex($activeCompetition);
        $roundsByIndex     = [];
        $fixturesByRoundId = [];
        $allFixtureIds     = [];
        foreach ($roundEntities as $round) {
            $roundsByIndex[$round->getRoundIndex()]          = $round;
            $fixtures                                        = $this->fixtureRepository->findByRoundOrderedBySlot($round);
            $fixturesByRoundId[$round->getId()->toRfc4122()] = $fixtures;
            foreach ($fixtures as $fixture) {
                $allFixtureIds[] = $fixture->getId();
            }
        }

        $resultsByFixtureId = $this->resultRepository->findByFixtureIds($allFixtureIds);
        $capacity           = $activeCompetition->getEntrantCapacity();
        $labels             = BracketLabeler::labelsForCapacity($capacity);

        $rounds = [];
        foreach ($labels as $i => $label) {
            $roundIndex = $i + 1;
            $key        = BracketLabeler::keyForLabel($label);
            $slotCount  = BracketLabeler::slotCountForRound($capacity, $roundIndex);
            $round      = $roundsByIndex[$roundIndex] ?? null;

            if ($round === null) {
                $placeholderFixtures = [];
                for ($slot = 0; $slot < $slotCount; $slot++) {
                    $placeholderFixtures[] = $this->buildTbdFixture($slot);
                }

                $rounds[] = [
                    'roundIndex'       => $roundIndex,
                    'key'              => $key,
                    'label'            => $label,
                    'status'           => 'future',
                    'drawAt'           => null,
                    'scheduledAt'      => null,
                    'startedAt'        => null,
                    'kickoffAt'        => null,
                    'matchesResolveAt' => null,
                    'fixtures'         => $placeholderFixtures,
                ];
                continue;
            }

            $existingFixtures = $fixturesByRoundId[$round->getId()->toRfc4122()] ?? [];
            if ($existingFixtures === []) {
                $fixtures = [];
                for ($slot = 0; $slot < $slotCount; $slot++) {
                    $fixtures[] = $this->buildTbdFixture($slot);
                }
            } else {
                $fixtures = array_map(
                    function (CompetitionFixture $f) use ($resultsByFixtureId): array {
                        $resultEntity  = $resultsByFixtureId[(string) $f->getId()] ?? null;
                        $resultSummary = $this->serializeResultSummary($resultEntity);

                        $score = null;
                        if ($resultEntity !== null) {
                            $score = [
                                'home'      => $resultEntity->getHomeScore(),
                                'away'      => $resultEntity->getAwayScore(),
                                'extraTime' => $resultEntity->isWentToExtraTime(),
                                'penalties' => $resultEntity->isWentToPenalties()
                                    ? ['home' => $resultEntity->getPenaltyHomeScore(), 'away' => $resultEntity->getPenaltyAwayScore()]
                                    : null,
                            ];
                        }

                        return [
                            'fixtureId'       => (string) $f->getId(),
                            'slotIndex'       => $f->getSlotIndex(),
                            'status'          => $f->getStatus()->value,
                            'homeEntrantId'   => $f->getHomeEntrant() !== null ? (string) $f->getHomeEntrant()->getId() : null,
                            'awayEntrantId'   => $f->getAwayEntrant() !== null ? (string) $f->getAwayEntrant()->getId() : null,
                            'winnerEntrantId' => $f->getWinnerEntrant() !== null ? (string) $f->getWinnerEntrant()->getId() : null,
                            'score'           => $score,
                            'matchId'         => $f->getStatus() === CompetitionFixtureStatus::COMPLETE ? (string) $f->getId() : null,
                            'home'            => $this->serializeEntrantSummary($f->getHomeEntrant()),
                            'away'            => $this->serializeEntrantSummary($f->getAwayEntrant()),
                            'result'          => $resultSummary,
                        ];
                    },
                    $existingFixtures,
                );
            }

            // Round 2..N placeholder scheduledAt is endsAt until Round N-1 finishes; only expose drawAt when it is genuinely scheduled.
            $isGenuineDrawAt = $roundIndex === 1
                || $round->getStatus() !== CompetitionRoundStatus::DRAW_PENDING
                || (($roundsByIndex[$roundIndex - 1] ?? null)?->getStatus() === CompetitionRoundStatus::RESULTS_PUBLISHED);
            $drawAtIso    = $isGenuineDrawAt ? $round->getScheduledAt()->format(DATE_ATOM) : $round->getScheduledAt()->format(DATE_ATOM);
            $kickoffAtIso = $round->getMatchesResolveAt()?->format(DATE_ATOM);

            $rounds[] = [
                'roundIndex'       => $round->getRoundIndex(),
                'key'              => $key,
                'label'            => $round->getLabel(),
                'status'           => $round->getStatus()->value,
                'drawAt'           => $drawAtIso,
                'scheduledAt'      => $round->getScheduledAt()->format(DATE_ATOM),
                'startedAt'        => $round->getStartedAt()?->format(DATE_ATOM),
                'kickoffAt'        => $kickoffAtIso,
                'matchesResolveAt' => $kickoffAtIso,
                'fixtures'         => $fixtures,
            ];
        }

        $summary = $this->serializeInstanceSummary($activeCompetition, $club);
        $payload = array_merge($summary, [
            'entrants'   => array_map(
                $this->serializeEntrantPreview(...),
                $this->entrantRepository->findByCompetitionOrderedByRegistration($activeCompetition),
            ),
            'rounds'     => $rounds,
            'serverTime' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        return new JsonResponse($payload, Response::HTTP_OK, ['ETag' => $etag]);
    }

    /**
     * Resolves the acting club for competition write endpoints.
     * Uses `X-Club-Id` when present, or `$fallbackClubId` (e.g. from the snapshot or body),
     * and returns `400 club_id_required` when neither is supplied and the user owns multiple
     * clubs or when `X-Club-Id` is explicitly blank.
     */
    private function resolveClubForWrite(User $user, Request $request, ?string $fallbackClubId = null): Club|JsonResponse
    {
        if ($request->headers->has(ClubResolver::CLUB_ID_HEADER)) {
            $rawHeader = trim((string) $request->headers->get(ClubResolver::CLUB_ID_HEADER));
            if ($rawHeader === '') {
                return $this->json([
                    'error'   => 'club_id_required',
                    'message' => 'The X-Club-Id header is required for competition write actions.',
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            return $this->clubResolver->resolveRequiredFromRequest($user, $fallbackClubId);
        } catch (ClubIdRequiredException) {
            return $this->json([
                'error'   => 'club_id_required',
                'message' => 'The X-Club-Id header is required for competition write actions.',
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    private function buildTbdFixture(int $slotIndex): array
    {
        return [
            'fixtureId'       => null,
            'slotIndex'       => $slotIndex,
            'status'          => 'tbd',
            'homeEntrantId'   => null,
            'awayEntrantId'   => null,
            'winnerEntrantId' => null,
            'score'           => null,
            'matchId'         => null,
            'home'            => null,
            'away'            => null,
            'result'          => null,
        ];
    }

    private function nextRoundDueAt(?CompetitionRound $currentRound): ?string
    {
        if ($currentRound === null) {
            return null;
        }

        $dueAt = $currentRound->getStatus() === CompetitionRoundStatus::DRAWN
            ? $currentRound->getMatchesResolveAt()
            : $currentRound->getScheduledAt();

        return $dueAt?->format(DATE_ATOM);
    }

    private function resolveOptionalClub(): ?Club
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }

        return $this->clubResolver->resolveFromRequest($user);
    }

    public function serializeInstanceSummary(ActiveCompetition $instance, ?Club $viewerClub = null): array
    {
        $template        = $instance->getTemplate();
        $currentRound    = $this->roundRepository->findCurrentRound($instance);
        $capacity        = $instance->getEntrantCapacity();
        $registeredCount = $this->entrantRepository->countForCompetition($instance);
        $totalRounds     = (int) round(log($capacity, 2));
        $entryFeePence   = $template->getEntryFeePence();

        $champion = null;
        $runnerUp = null;
        if ($instance->getStatus() === ActiveCompetitionStatus::COMPLETED) {
            $finalFixture = $this->fixtureRepository->findFinalFixtureForCompetition($instance);
            if ($finalFixture !== null && $finalFixture->getWinnerEntrant() !== null) {
                $winner   = $finalFixture->getWinnerEntrant();
                $home     = $finalFixture->getHomeEntrant();
                $away     = $finalFixture->getAwayEntrant();
                $loser    = ($home !== null && $away !== null) ? ($winner === $home ? $away : $home) : null;
                $champion = $this->serializeEntrantSummary($winner);
                $runnerUp = $this->serializeEntrantSummary($loser);
            }
        }

        $version = $this->computeInstanceVersion($instance, $registeredCount);

        return [
            'instanceId'            => (string) $instance->getId(),
            'templateId'            => (string) $template->getId(),
            'templateName'          => $template->getName(),
            'status'                => $instance->getStatus()->value,
            'registrationOpensAt'   => $instance->getRegistrationOpenedAt()->format(DATE_ATOM),
            'startsAt'              => $instance->getStartsAt()?->format(DATE_ATOM),
            'endsAt'                => $instance->getEndsAt()?->format(DATE_ATOM),
            'completedAt'           => $instance->getCompletedAt()?->format(DATE_ATOM),
            'cancelledAt'           => $instance->getCancelledAt()?->format(DATE_ATOM),
            'cancelReason'          => $instance->getCancellationReason(),
            'trophy'                => $this->normalizeTrophy($template->getTrophy()),
            'entrantCapacity'       => $capacity,
            'registeredCount'       => $registeredCount,
            'slotsRemaining'        => max(0, $capacity - $registeredCount),
            'bracketSize'           => $capacity,
            'byeCount'              => 0,
            'totalRounds'           => $totalRounds,
            'durationOption'        => $instance->getDurationOption()->value,
            'durationHours'         => $instance->getDurationOption()->toHours(),
            'entryConditions'       => $this->serializeEntryConditions($template),
            'entryFeePence'         => $entryFeePence,
            'entryFeePerRound'      => $entryFeePence,
            'entryFeePerRoundPence' => $entryFeePence,
            'prizes'                => $this->serializePrizes($template),
            'champion'              => $champion,
            'runnerUp'              => $runnerUp,
            'nextRoundLabel'        => $currentRound?->getLabel(),
            'nextRoundAt'           => $this->nextRoundDueAt($currentRound),
            'version'               => $version,
            'viewer'                => $this->buildViewerBlock($instance, $viewerClub, $currentRound),
        ];
    }

    private function buildViewerBlock(ActiveCompetition $instance, ?Club $viewerClub, ?CompetitionRound $currentRound): ?array
    {
        if ($viewerClub === null) {
            return null;
        }

        $entrant     = $this->entrantRepository->findByCompetitionAndClub($instance, $viewerClub);
        $relation    = $entrant !== null ? $entrant->getStatus()->value : 'none';
        $eligibility = null;

        if ($instance->getStatus() === ActiveCompetitionStatus::REGISTERING) {
            $eval        = $this->eligibilityEvaluator->evaluate($instance->getTemplate(), $viewerClub);
            $eligibility = [
                'eligible' => $eval->eligible,
                'reasons'  => $eval->structuredReasons,
            ];
        }

        $nextMatchId = null;
        $snapshot    = null;

        if ($entrant !== null && $entrant->getStatus() !== CompetitionEntrantStatus::WITHDRAWN) {
            $pendingFixture = $this->fixtureRepository->findNextPendingFixtureForEntrant($entrant);
            $nextMatchId    = $pendingFixture !== null ? (string) $pendingFixture->getId() : null;

            $locksAt = null;
            if ($currentRound !== null && $currentRound->getStatus() === CompetitionRoundStatus::DRAW_PENDING) {
                $locksAt = $currentRound->getScheduledAt()->format(DATE_ATOM);
            }

            $acceptedAt = $entrant->getSnapshotJson()['clientSnapshotAt'] ?? $entrant->getRegisteredAt()->format(DATE_ATOM);
            $snapshot   = [
                'version'    => $entrant->getSnapshotVersion(),
                'acceptedAt' => is_string($acceptedAt) ? $acceptedAt : $entrant->getRegisteredAt()->format(DATE_ATOM),
                'locksAt'    => $locksAt,
            ];
        }

        return [
            'relation'    => $relation,
            'entrantId'   => $entrant !== null && $entrant->getStatus() !== CompetitionEntrantStatus::WITHDRAWN ? (string) $entrant->getId() : null,
            'eligibility' => $eligibility,
            'nextMatchId' => $nextMatchId,
            'snapshot'    => $snapshot,
        ];
    }

    private function computeInstanceVersion(ActiveCompetition $instance, int $registeredCount): int
    {
        $rounds     = $this->roundRepository->findByCompetitionOrderedByIndex($instance);
        $roundState = 0;
        foreach ($rounds as $r) {
            $roundState += match ($r->getStatus()) {
                CompetitionRoundStatus::DRAW_PENDING      => 1,
                CompetitionRoundStatus::DRAWN             => 2,
                CompetitionRoundStatus::RESULTS_PUBLISHED => 3,
                CompetitionRoundStatus::CANCELLED         => 4,
            };
        }

        return 1 + $registeredCount + $roundState;
    }

    private function computeEtag(ActiveCompetition $instance, ?Club $club): string
    {
        $registeredCount = $this->entrantRepository->countForCompetition($instance);
        $version         = $this->computeInstanceVersion($instance, $registeredCount);
        $clubPart        = $club !== null ? (string) $club->getId() : 'anon';
        $entrantVersion  = 0;
        if ($club !== null) {
            $entrant        = $this->entrantRepository->findByCompetitionAndClub($instance, $club);
            $entrantVersion = $entrant !== null ? $entrant->getSnapshotVersion() : 0;
        }

        return sprintf('W/"%s-%s-%d-%s-%d"', $instance->getId(), $instance->getStatus()->value, $version, $clubPart, $entrantVersion);
    }

    private function normalizeTrophy(?array $trophy): ?array
    {
        if ($trophy === null) {
            return null;
        }

        $styleRaw  = (string) ($trophy['style'] ?? 'classic');
        $finishRaw = (string) ($trophy['finish'] ?? 'gold');

        $style  = TrophyStyle::tryFrom($styleRaw)?->value ?? TrophyStyle::CLASSIC->value;
        $finish = TrophyFinish::tryFrom($finishRaw)?->value ?? TrophyFinish::GOLD->value;

        return [
            'style'           => $style,
            'finish'          => $finish,
            'ribbons'         => (bool) ($trophy['ribbons'] ?? false),
            'ribbonPrimary'   => is_string($trophy['ribbonPrimary'] ?? null) ? $trophy['ribbonPrimary'] : '#c8202f',
            'ribbonSecondary' => is_string($trophy['ribbonSecondary'] ?? null) ? $trophy['ribbonSecondary'] : '#f4f3ee',
        ];
    }

    private function serializePrizes(CompetitionTemplate $template): array
    {
        $victorPrize = $template->getVictorPrize();

        return [
            'victorPrize'      => $victorPrize,
            'victorPrizePence' => $victorPrize,
            'bonusRewards'     => array_map(
                static fn (RewardTemplate $reward) => [
                    'slug'        => $reward->getSlug(),
                    'name'        => $reward->getName(),
                    'description' => $reward->getDescription(),
                    'effects'     => $reward->getEffects(),
                ],
                $template->getRewardTemplates()->toArray(),
            ),
        ];
    }

    private function serializeEntryConditions(CompetitionTemplate $template): array
    {
        return [
            'minClubReputation' => $template->getMinClubReputation(),
            'minClubAgeSeasons' => $template->getMinClubAgeSeasons(),
            'allowedTiers'      => $template->getAllowedTiers(),
        ];
    }

    public function serializeEntrantSummary(?CompetitionEntrant $entrant): ?array
    {
        if ($entrant === null) {
            return null;
        }

        $snapshotClub = $entrant->getSnapshotJson()['club'] ?? [];
        if (!is_array($snapshotClub)) {
            $snapshotClub = [];
        }

        $clubEntity   = $entrant->getClub();
        $name         = (string) ($snapshotClub['name'] ?? $clubEntity->getName());
        $abbreviation = (string) ($snapshotClub['abbreviation'] ?? $clubEntity->getAbbreviation() ?? ClubInitializationService::generateAbbreviation($name));
        $reputation   = is_numeric($snapshotClub['reputation'] ?? null)
            ? (int) $snapshotClub['reputation']
            : $clubEntity->getReputation();
        $ovr          = is_numeric($snapshotClub['ovr'] ?? null)
            ? (int) $snapshotClub['ovr']
            : CompetitionRegistrationService::computeStartingXiOvr($entrant->getSnapshotJson());

        $badgeShape = $snapshotClub['badgeShape'] ?? ($clubEntity->getBadgeConfig()['badgeShape'] ?? null);
        if ($badgeShape === 'circle' || $badgeShape === 'badge') {
            $badgeShape = 'round';
        }

        $badgeConfig = $snapshotClub['badgeConfig'] ?? $clubEntity->getBadgeConfig();
        if (is_array($badgeConfig) && in_array($badgeConfig['badgeShape'] ?? null, ['circle', 'badge'], true)) {
            $badgeConfig['badgeShape'] = 'round';
        }

        return [
            'entrantId'      => (string) $entrant->getId(),
            'clubId'         => (string) $clubEntity->getId(),
            'clubName'       => $name,
            'name'           => $name,
            'abbreviation'   => $abbreviation,
            'country'        => $snapshotClub['country'] ?? $clubEntity->getCountry(),
            'ovr'            => $ovr,
            'reputation'     => $reputation,
            'reputationTier' => Tier::fromScore($reputation)->value,
            'seed'           => $entrant->getSeed(),
            'homeKitConfig'  => $snapshotClub['homeKitConfig'] ?? $clubEntity->getHomeKitConfig(),
            'awayKitConfig'  => $snapshotClub['awayKitConfig'] ?? $clubEntity->getAwayKitConfig(),
            'badgeConfig'    => $badgeConfig,
            'badgeShape'     => $badgeShape,
            'homePrimary'    => $snapshotClub['homePrimary'] ?? null,
            'homeSecondary'  => $snapshotClub['homeSecondary'] ?? null,
            'awayPrimary'    => $snapshotClub['awayPrimary'] ?? null,
            'awaySecondary'  => $snapshotClub['awaySecondary'] ?? null,
        ];
    }

    /** Pre-bracket entrant listing (used before rounds/fixtures exist) — same club display fields as serializeEntrantSummary(), plus registration metadata. */
    public function serializeEntrantPreview(CompetitionEntrant $entrant): array
    {
        return [
            ...$this->serializeEntrantSummary($entrant),
            'registeredAt' => $entrant->getRegisteredAt()->format(DATE_ATOM),
            'status'       => $entrant->getStatus()->value,
        ];
    }

    private function serializeResultSummary(?CompetitionResult $result): ?array
    {
        return $result?->toClientSummary();
    }

    private function buildSnapshotArray(array $club, array $players, array $staff, array $facilities, ?string $clientSnapshotAt): array
    {
        return [
            'club'             => $club,
            'players'          => $players,
            'staff'            => $staff,
            'facilities'       => $facilities,
            'clientSnapshotAt' => $clientSnapshotAt,
        ];
    }
}
