<?php

namespace App\Controller\Api;

use App\Dto\Competition\CompetitionRegisterRequest;
use App\Dto\Competition\CompetitionResubmitRequest;
use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\User;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Repository\Competition\ActiveCompetitionRepository;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Repository\Competition\CompetitionFixtureRepository;
use App\Repository\Competition\CompetitionRoundRepository;
use App\Repository\Competition\CompetitionTemplateRepository;
use App\Service\ClubResolver;
use App\Service\Competition\CompetitionRegistrationService;
use App\Service\Competition\EligibilityEvaluator;
use App\Service\Competition\SnapshotValidator;
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
        private readonly EligibilityEvaluator $eligibilityEvaluator,
        private readonly SnapshotValidator $snapshotValidator,
        private readonly CompetitionRegistrationService $registrationService,
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

        $rounds = [];
        foreach ($this->roundRepository->findByCompetitionOrderedByIndex($activeCompetition) as $round) {
            $fixtures = array_map(
                fn (CompetitionFixture $f) => [
                    'fixtureId' => (string) $f->getId(),
                    'slotIndex' => $f->getSlotIndex(),
                    'status'    => $f->getStatus()->value,
                    'home'      => $this->serializeEntrantSummary($f->getHomeEntrant()),
                    'away'      => $this->serializeEntrantSummary($f->getAwayEntrant()),
                    // Wired up once results are produced (DeterministicEngine, Phase 1 step 4).
                    'result'    => null,
                ],
                $this->fixtureRepository->findByRoundOrderedBySlot($round),
            );

            $rounds[] = [
                'roundIndex'  => $round->getRoundIndex(),
                'label'       => $round->getLabel(),
                'status'      => $round->getStatus()->value,
                'scheduledAt' => $round->getScheduledAt()->format(DATE_ATOM),
                'fixtures'    => $fixtures,
            ];
        }

        return $this->json([
            'instanceId'   => (string) $activeCompetition->getId(),
            'templateName' => $activeCompetition->getTemplate()->getName(),
            'status'       => $activeCompetition->getStatus()->value,
            'startsAt'     => $activeCompetition->getStartsAt()?->format(DATE_ATOM),
            'endsAt'       => $activeCompetition->getEndsAt()?->format(DATE_ATOM),
            'rounds'       => $rounds,
        ]);
    }

    /** No auth requirement on this action — best-effort: personalize if a valid JWT was presented, else omit club-specific fields. */
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
        return [
            'instanceId'   => (string) $instance->getId(),
            'templateName' => $instance->getTemplate()->getName(),
            'status'       => $instance->getStatus()->value,
            'startsAt'     => $instance->getStartsAt()?->format(DATE_ATOM),
            'endsAt'       => $instance->getEndsAt()?->format(DATE_ATOM),
            'completedAt'  => $instance->getCompletedAt()?->format(DATE_ATOM),
        ];
    }

    private function serializeEntrantSummary(?CompetitionEntrant $entrant): ?array
    {
        if ($entrant === null) {
            return null;
        }

        return [
            'entrantId' => (string) $entrant->getId(),
            'clubName'  => $entrant->getSnapshotJson()['club']['name'] ?? null,
            'seed'      => $entrant->getSeed(),
        ];
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
