<?php

namespace App\Controller\Api;

use App\Dto\Competition\CompetitionRegisterRequest;
use App\Dto\Competition\CompetitionResubmitRequest;
use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\User;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Exception\ClubIdRequiredException;
use App\Repository\Competition\ActiveCompetitionRepository;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Repository\Competition\CompetitionTemplateRepository;
use App\Service\ClubResolver;
use App\Service\Competition\CompetitionRegistrationService;
use App\Service\Competition\CompetitionResponseCacheService;
use App\Service\Competition\CompetitionResponseBuilder;
use App\Service\Competition\EligibilityEvaluator;
use App\Service\Competition\SnapshotValidator;
use App\Service\Notification\PushNotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\DBAL\Exception\DriverException;
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
        private readonly EligibilityEvaluator $eligibilityEvaluator,
        private readonly SnapshotValidator $snapshotValidator,
        private readonly CompetitionRegistrationService $registrationService,
        private readonly PushNotificationService $pushNotificationService,
        private readonly CompetitionResponseBuilder $responseBuilder,
        private readonly CompetitionResponseCacheService $responseCache,
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
            'competitions' => array_map(fn (ActiveCompetition $a) => $this->responseBuilder->serializeInstanceSummary($a, $club), $pageRows),
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
            $summary     = $this->responseBuilder->serializeInstanceSummary($latest, null);
            $champions[] = [
                'templateId'      => (string) $template->getId(),
                'templateName'    => $template->getName(),
                'entrantCapacity' => $latest->getEntrantCapacity(),
                'trophy'          => $this->responseBuilder->normalizeTrophy($template->getTrophy()),
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

            $row             = $this->responseBuilder->serializeInstanceSummary($instance, $club);
            $row['entrants'] = array_map(
                $this->responseBuilder->serializeEntrantPreview(...),
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
            'running'           => array_map(fn (ActiveCompetition $a) => $this->responseBuilder->serializeInstanceSummary($a, $club), $this->activeCompetitionRepository->findByStatus(ActiveCompetitionStatus::RUNNING)),
            'recentlyCompleted' => array_map(fn (ActiveCompetition $a) => $this->responseBuilder->serializeInstanceSummary($a, $club), $this->activeCompetitionRepository->findRecentlyCompleted(10)),
            'serverTime'        => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);
    }

    /** Locked-full, not-yet-completed instances — same per-item shape as available()'s running/recentlyCompleted rows. */
    #[Route('/active', name: 'api_competitions_active', methods: ['GET'])]
    public function active(): JsonResponse
    {
        $club = $this->resolveOptionalClub();

        return $this->json([
            'active'     => array_map(fn (ActiveCompetition $a) => $this->responseBuilder->serializeInstanceSummary($a, $club), $this->activeCompetitionRepository->findActive()),
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
                $this->buildSnapshotArray($dto->club, $dto->players, $dto->staff, $dto->clientSnapshotAt, $dto->facilities),
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
        } catch (DriverException $e) {
            // Postgres lock_timeout (SQLSTATE 55P03) — set per-transaction in register(). The
            // competition's row was held by another registration for longer than
            // CompetitionRegistrationService::LOCK_TIMEOUT_SECONDS, which means contention,
            // not a bad request: nothing was written and the same payload will succeed on a
            // retry. 503 + Retry-After says exactly that, where the alternative is a 500 that
            // looks like a bug and that a client has no reason to retry.
            if ($e->getSQLState() !== '55P03') {
                throw $e;
            }

            return $this->json(
                ['error' => 'registration_busy', 'message' => 'This competition is handling other registrations. Retry shortly.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Retry-After' => '2'],
            );
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
            'entrant'     => $this->responseBuilder->serializeEntrantPreview($entrant),
            'competition' => $this->responseBuilder->serializeInstanceSummary($activeCompetition, $club),
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
            $entrant = $this->registrationService->withdraw($activeCompetition, $club);
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'withdrawal_closed' => $this->json(['error' => 'withdrawal_closed'], Response::HTTP_CONFLICT),
                'not_registered'    => $this->json(['error' => 'not_registered'], Response::HTTP_NOT_FOUND),
                default             => throw $e,
            };
        }

        return $this->json([
            // Read off the entrant rather than hardcoded, so all three write endpoints mean
            // the same thing by `status`. The value is unchanged — CompetitionEntrantStatus
            // ::WITHDRAWN is the string 'withdrawn' — so unlike the equivalent fix on
            // /resubmit this one breaks nothing; it was only accidentally correct before.
            'status'      => $entrant->getStatus()->value,
            // Nested as on /register and /resubmit. Worth returning on a withdrawal too: a
            // club withdrawing a FAILED entry gets back submissionStatus 'failed', which is
            // how a client confirms it withdrew the thing it meant to.
            'entrant'     => $this->responseBuilder->serializeEntrantPreview($entrant),
            'competition' => $this->responseBuilder->serializeInstanceSummary($activeCompetition, $club),
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
            $existingAt = $entrant->getClientSnapshotAt();
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

        $this->registrationService->resubmit($entrant, $this->buildSnapshotArray($dto->club, $dto->players, $dto->staff, $dto->clientSnapshotAt, $dto->facilities));

        $currentRound = $this->roundRepository->findCurrentRound($activeCompetition);
        $locksAt      = $currentRound !== null && $currentRound->getStatus() === CompetitionRoundStatus::DRAW_PENDING
            ? $currentRound->getScheduledAt()->format(DATE_ATOM)
            : null;

        // $entrant is current here: resubmit() refreshes it after its raw-SQL writes, which is
        // also what retires the `getSnapshotVersion() + 1` this used to return — the stored
        // value is now the true one rather than a stale read with the increment reapplied.
        return $this->json([
            'entrantId'       => (string) $entrant->getId(),
            'snapshotVersion' => $entrant->getSnapshotVersion(),
            // Same meaning as register()'s `status`: the entry's standing in the competition.
            // It used to be the string 'accepted', about the resubmission rather than the
            // entry — two different vocabularies under one key across two endpoints a client
            // calls in sequence. Acceptance is already carried by the 200 (the refusals are
            // 404/409), so nothing was lost in making this consistent.
            'status'          => $entrant->getStatus()->value,
            'locksAt'         => $locksAt,
            // Nested, exactly as register() returns it, so `entrant.submissionStatus` is read
            // the same way from both. Always 'confirmed' here — resubmit writes inline rather
            // than queueing — but stated rather than implied, since this is how a client
            // confirms a FAILED entry actually recovered.
            'entrant'         => $this->responseBuilder->serializeEntrantPreview($entrant),
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

        $club         = $this->resolveOptionalClub();
        $fingerprint  = $this->responseBuilder->stateFingerprint($activeCompetition);
        $etag         = $this->responseBuilder->etagFor($activeCompetition, $club, $fingerprint);

        $ifNone = $request->headers->get('If-None-Match');
        if ($ifNone !== null && trim($ifNone) === $etag) {
            return new JsonResponse(null, Response::HTTP_NOT_MODIFIED, ['ETag' => $etag]);
        }

        // The bracket itself is identical for every caller, so it is served from a prebuilt
        // document rather than reassembled per request — which is what this endpoint used to
        // do, decoding every entrant's squad to do it. The cron passes that change a bracket
        // rebuild this, so a request normally finds it ready; a miss just builds it here.
        $document = $this->responseCache->getOrBuild(
            $activeCompetition,
            $fingerprint,
            fn (): array => $this->responseBuilder->sharedDocument($activeCompetition),
        );

        // `viewer` already exists in the document as null, so this overwrites rather than
        // appends: a client sees the same key order whether the document was cached or not.
        $payload = array_merge($document, [
            'viewer'     => $this->responseBuilder->viewerBlock($activeCompetition, $club),
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

    private function resolveOptionalClub(): ?Club
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }

        return $this->clubResolver->resolveFromRequest($user);
    }

    /**
     * The envelope handed to the registration service, which splits it across the entrant's
     * display block, its squad row, and its verbatim submission row.
     *
     * `facilities` rides along for the submission row only. It is deliberately NOT put back
     * on the entrant or the squad — it was removed from both because every bracket render
     * decoded it and nothing read it, and that is still true.
     *
     * @param array<string, mixed> $facilities
     */
    private function buildSnapshotArray(array $club, array $players, array $staff, ?string $clientSnapshotAt, array $facilities = []): array
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
