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
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\CompetitionRoundStatus;
use App\Repository\Competition\ActiveCompetitionRepository;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionResultRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Repository\Competition\CompetitionTemplateRepository;
use App\Service\ClubResolver;
use App\Service\Competition\CompetitionRegistrationService;
use App\Service\Competition\EligibilityEvaluator;
use App\Service\Competition\SnapshotValidator;
use App\Service\Notification\PushNotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `available` and `{id}` are PUBLIC (security.yaml access_control) so the web landing
 * page can hit them directly — no class-level IsGranted here, register/resubmit carry
 * it per-action instead. Club resolution mode differs per action, see the plan's API
 * contract table: header-based for register (live tap-to-register), body-carried
 * clubId for resubmit (offline-queue parity with SyncRequest), none for the two GETs.
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

            $row = [
                'instanceId'          => (string) $instance->getId(),
                'templateId'          => (string) $template->getId(),
                'templateName'        => $template->getName(),
                'entrantCapacity'     => $instance->getEntrantCapacity(),
                'registeredCount'     => $this->entrantRepository->countForCompetition($instance),
                'status'              => $instance->getStatus()->value,
                'registrationOpensAt' => $instance->getRegistrationOpenedAt()->format(DATE_ATOM),
                'trophyImage'         => $template->getTrophyImage(),
                'trophyColour'        => $template->getTrophyColour()?->value,
                'durationOption'      => $instance->getDurationOption()->value,
                'entryConditions'     => $this->serializeEntryConditions($template),
                'entryFeePerRound'    => $template->getEntryFeePerRound(),
                'prizes'              => $this->serializePrizes($template),
                'entrants'            => array_map(
                    $this->serializeEntrantPreview(...),
                    $this->entrantRepository->findByCompetitionOrderedByRegistration($instance),
                ),
                'nextRoundLabel'      => null, // REGISTERING instances have no rounds yet — see ActiveCompetition's class docblock
                'nextRoundAt'         => null,
            ];

            if ($club !== null) {
                $existing               = $this->entrantRepository->findByCompetitionAndClub($instance, $club);
                $row['alreadyRegistered'] = $existing !== null;
                $eligibility              = $this->eligibilityEvaluator->evaluate($template, $club);
                $row['eligibility']         = ['eligible' => $eligibility->eligible, 'reasons' => $eligibility->reasons];
            }

            $open[] = $row;
        }

        return $this->json([
            'open'              => $open,
            'running'           => array_map($this->serializeInstanceSummary(...), $this->activeCompetitionRepository->findByStatus(ActiveCompetitionStatus::RUNNING)),
            'recentlyCompleted' => array_map($this->serializeInstanceSummary(...), $this->activeCompetitionRepository->findRecentlyCompleted(10)),
        ]);
    }

    /** Locked-full, not-yet-completed instances — same per-item shape as available()'s running/recentlyCompleted rows. */
    #[Route('/active', name: 'api_competitions_active', methods: ['GET'])]
    public function active(): JsonResponse
    {
        return $this->json([
            'active' => array_map($this->serializeInstanceSummary(...), $this->activeCompetitionRepository->findActive()),
        ]);
    }

    #[Route('/{id}/register', name: 'api_competitions_register', methods: ['POST'])]
    #[IsGranted('ROLE_CLUB')]
    public function register(string $id, #[MapRequestPayload] CompetitionRegisterRequest $dto): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);
        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
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
            return $this->json(['error' => 'not_eligible', 'reasons' => $eligibility->reasons], Response::HTTP_FORBIDDEN);
        }

        $violations = $this->snapshotValidator->validate($dto->club, $dto->players, $dto->staff, (string) $club->getId());
        if ($violations !== []) {
            return $this->json(['error' => 'invalid_snapshot', 'violations' => $violations], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result  = $this->registrationService->register($activeCompetition, $club, $this->buildSnapshotArray($dto->club, $dto->players, $dto->staff, $dto->facilities, $dto->clientSnapshotAt));
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
            'entrantId'  => (string) $entrant->getId(),
            'instanceId' => (string) $activeCompetition->getId(),
            'seed'       => $entrant->getSeed(),
            'status'     => $entrant->getStatus()->value,
        ], $result['wasNewRegistration'] ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/{id}/resubmit', name: 'api_competitions_resubmit', methods: ['POST'])]
    #[IsGranted('ROLE_CLUB')]
    public function resubmit(string $id, #[MapRequestPayload] CompetitionResubmitRequest $dto): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $club = $this->clubResolver->resolve($user, $dto->clubId);
        if ($club === null) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        $activeCompetition = $this->activeCompetitionRepository->find($id);
        if ($activeCompetition === null) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $entrant = $this->entrantRepository->findByCompetitionAndClub($activeCompetition, $club);
        if ($entrant === null) {
            return $this->json(['error' => 'not_registered'], Response::HTTP_NOT_FOUND);
        }

        $stillInPlay = in_array($entrant->getStatus(), [CompetitionEntrantStatus::REGISTERED, CompetitionEntrantStatus::ACTIVE], true);
        if (!$stillInPlay || !$this->roundRepository->hasUpcomingRound($activeCompetition)) {
            return $this->json(['error' => 'resubmission_window_closed'], Response::HTTP_CONFLICT);
        }

        $violations = $this->snapshotValidator->validate($dto->club, $dto->players, $dto->staff, (string) $club->getId());
        if ($violations !== []) {
            return $this->json(['error' => 'invalid_snapshot', 'violations' => $violations], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->registrationService->resubmit($entrant, $this->buildSnapshotArray($dto->club, $dto->players, $dto->staff, $dto->facilities, $dto->clientSnapshotAt));

        return $this->json([
            'entrantId'       => (string) $entrant->getId(),
            'snapshotVersion' => $entrant->getSnapshotVersion() + 1,
            'status'          => 'accepted',
        ]);
    }

    #[Route('/{id}', name: 'api_competitions_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $activeCompetition = $this->activeCompetitionRepository->find($id);
        if ($activeCompetition === null) {
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $roundEntities     = $this->roundRepository->findByCompetitionOrderedByIndex($activeCompetition);
        $fixturesByRoundId = [];
        $allFixtureIds     = [];
        foreach ($roundEntities as $round) {
            $fixtures                                        = $this->fixtureRepository->findByRoundOrderedBySlot($round);
            $fixturesByRoundId[$round->getId()->toRfc4122()] = $fixtures;
            foreach ($fixtures as $fixture) {
                $allFixtureIds[] = $fixture->getId();
            }
        }

        $resultsByFixtureId = $this->resultRepository->findByFixtureIds($allFixtureIds);

        $rounds = [];
        foreach ($roundEntities as $round) {
            $fixtures = array_map(
                fn (CompetitionFixture $f) => [
                    'fixtureId' => (string) $f->getId(),
                    'slotIndex' => $f->getSlotIndex(),
                    'status'    => $f->getStatus()->value,
                    'home'      => $this->serializeEntrantSummary($f->getHomeEntrant()),
                    'away'      => $this->serializeEntrantSummary($f->getAwayEntrant()),
                    'result'    => $this->serializeResultSummary($resultsByFixtureId[(string) $f->getId()] ?? null),
                ],
                $fixturesByRoundId[$round->getId()->toRfc4122()],
            );

            $rounds[] = [
                'roundIndex'       => $round->getRoundIndex(),
                'label'            => $round->getLabel(),
                'status'           => $round->getStatus()->value,
                'scheduledAt'      => $round->getScheduledAt()->format(DATE_ATOM),
                'startedAt'        => $round->getStartedAt()?->format(DATE_ATOM),
                'matchesResolveAt' => $round->getMatchesResolveAt()?->format(DATE_ATOM),
                'fixtures'         => $fixtures,
            ];
        }

        $currentRound = $this->roundRepository->findCurrentRound($activeCompetition);

        return $this->json([
            'instanceId'      => (string) $activeCompetition->getId(),
            'templateId'      => (string) $activeCompetition->getTemplate()->getId(),
            'templateName'    => $activeCompetition->getTemplate()->getName(),
            'status'          => $activeCompetition->getStatus()->value,
            'startsAt'        => $activeCompetition->getStartsAt()?->format(DATE_ATOM),
            'endsAt'          => $activeCompetition->getEndsAt()?->format(DATE_ATOM),
            'trophyImage'     => $activeCompetition->getTemplate()->getTrophyImage(),
            'trophyColour'    => $activeCompetition->getTemplate()->getTrophyColour()?->value,
            'entrantCapacity' => $activeCompetition->getEntrantCapacity(),
            'registeredCount' => $this->entrantRepository->countForCompetition($activeCompetition),
            'durationOption'  => $activeCompetition->getDurationOption()->value,
            'entryConditions' => $this->serializeEntryConditions($activeCompetition->getTemplate()),
            'entryFeePerRound' => $activeCompetition->getTemplate()->getEntryFeePerRound(),
            'prizes'          => $this->serializePrizes($activeCompetition->getTemplate()),
            'entrants'        => array_map(
                $this->serializeEntrantPreview(...),
                $this->entrantRepository->findByCompetitionOrderedByRegistration($activeCompetition),
            ),
            'nextRoundLabel'  => $currentRound?->getLabel(),
            'nextRoundAt'     => $this->nextRoundDueAt($currentRound),
            'rounds'          => $rounds,
        ]);
    }

    /** No auth requirement on this action — best-effort: personalize if a valid JWT was presented, else omit club-specific fields. */
    /**
     * Whichever timestamp is next relevant for $currentRound: the draw due-time while it's
     * still DRAW_PENDING, or the resolve due-time once it's DRAWN — keeps the pre-existing
     * "nextRoundAt is when the next thing happens" client contract working unchanged across
     * the decoupled draw/resolve phases.
     */
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

    private function serializeInstanceSummary(ActiveCompetition $instance): array
    {
        $currentRound = $this->roundRepository->findCurrentRound($instance);

        return [
            'instanceId'      => (string) $instance->getId(),
            'templateId'      => (string) $instance->getTemplate()->getId(),
            'templateName'    => $instance->getTemplate()->getName(),
            'status'          => $instance->getStatus()->value,
            'startsAt'        => $instance->getStartsAt()?->format(DATE_ATOM),
            'endsAt'          => $instance->getEndsAt()?->format(DATE_ATOM),
            'completedAt'     => $instance->getCompletedAt()?->format(DATE_ATOM),
            'trophyImage'     => $instance->getTemplate()->getTrophyImage(),
            'trophyColour'    => $instance->getTemplate()->getTrophyColour()?->value,
            'entrantCapacity' => $instance->getEntrantCapacity(),
            'registeredCount' => $this->entrantRepository->countForCompetition($instance),
            'durationOption'  => $instance->getDurationOption()->value,
            'entryConditions' => $this->serializeEntryConditions($instance->getTemplate()),
            'entryFeePerRound' => $instance->getTemplate()->getEntryFeePerRound(),
            'prizes'          => $this->serializePrizes($instance->getTemplate()),
            'nextRoundLabel'  => $currentRound?->getLabel(),
            'nextRoundAt'     => $this->nextRoundDueAt($currentRound),
        ];
    }

    /**
     * Only a winner-facing prize exists in the data model today — there is no per-round
     * prize concept (CompetitionTemplate has a single victorPrize plus optional
     * rewardTemplates, both applied once at completion; see RewardApplierService). Pence.
     */
    private function serializePrizes(CompetitionTemplate $template): array
    {
        return [
            'victorPrize'  => $template->getVictorPrize(),
            'bonusRewards' => array_map(
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

    /**
     * Badge/kit fields are read straight off the entrant's snapshot club object — see
     * SnapshotValidator's docblock: those keys are client-supplied and deliberately
     * unchecked, so any of them may be absent on an older or malformed snapshot.
     */
    private function serializeEntrantSummary(?CompetitionEntrant $entrant): ?array
    {
        if ($entrant === null) {
            return null;
        }

        $club = $entrant->getSnapshotJson()['club'] ?? [];

        return [
            'entrantId'     => (string) $entrant->getId(),
            'clubName'      => $club['name'] ?? null,
            'seed'          => $entrant->getSeed(),
            'badgeShape'    => $club['badgeShape'] ?? null,
            'homePrimary'   => $club['homePrimary'] ?? null,
            'homeSecondary' => $club['homeSecondary'] ?? null,
            'awayPrimary'   => $club['awayPrimary'] ?? null,
            'awaySecondary' => $club['awaySecondary'] ?? null,
        ];
    }

    /** Pre-bracket entrant listing (used before rounds/fixtures exist) — same club display fields as serializeEntrantSummary(), plus registration metadata. */
    private function serializeEntrantPreview(CompetitionEntrant $entrant): array
    {
        return [
            ...$this->serializeEntrantSummary($entrant),
            'registeredAt' => $entrant->getRegisteredAt()->format(DATE_ATOM),
            'status'       => $entrant->getStatus()->value,
        ];
    }

    /**
     * Score-only summary for the public bracket view — the full event log is an admin-only
     * concern, not part of this lightweight client payload.
     */
    private function serializeResultSummary(?CompetitionResult $result): ?array
    {
        return $result?->toClientSummary();
    }

    private function buildSnapshotArray(array $club, array $players, array $staff, array $facilities, ?string $clientSnapshotAt): array
    {
        return [
            'club'              => $club,
            'players'           => $players,
            'staff'             => $staff,
            'facilities'        => $facilities,
            'clientSnapshotAt'  => $clientSnapshotAt,
        ];
    }
}
