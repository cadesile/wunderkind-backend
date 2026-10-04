<?php

namespace App\Controller\Api;

use App\Dto\ClubInitRequest;
use App\Entity\Club;
use App\Entity\Investor;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Enum\Country;
use App\Exception\ClubNameTakenException;
use App\Exception\InvalidCountryCodeException;
use App\Repository\ClubRepository;
use App\Repository\NpcClubRepository;
use App\Repository\SyncRecordRepository;
use App\Service\ClubInitializationService;
use App\Service\NpcClubGenerationService;
use App\Service\ClubResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/club')]
class ClubController extends AbstractController
{
    // Locale used to alphabetize name-options results per country, so accented
    // characters (e.g. Spanish "Ávila") sort next to their unaccented
    // neighbors instead of at the end of the list (PHP's plain sort() sorts
    // by raw UTF-8 byte value, which pushes multi-byte accented letters past
    // all plain ASCII ones). The locale table lives on App\Enum\Country.

    public function __construct(private readonly ClubResolver $clubResolver) {}

    #[Route('/foreign', name: 'api_clubs_foreign', methods: ['GET'])]
    public function foreignClubs(Request $request, NpcClubRepository $npcClubRepo): JsonResponse
    {
        $country      = strtoupper($request->query->get('country', 'EN'));
        $limitPerTier = max(1, min(10, (int) $request->query->get('limit_per_tier', 3)));

        $clubs = $npcClubRepo->findForeignClubs($country, null, $limitPerTier);

        return $this->json([
            'country' => $country,
            'clubs'   => $clubs,
        ]);
    }

    #[Route('/name-options', name: 'api_clubs_name_options', methods: ['GET'])]
    public function nameOptions(
        Request $request,
        NpcClubGenerationService $npcClubGenerationService,
        NpcClubRepository $npcClubRepo,
    ): JsonResponse {
        $country  = strtoupper($request->query->get('country', 'EN'));
        $cities   = array_merge(
            $npcClubGenerationService->getPlaceNames($country) ?: $npcClubGenerationService->getPlaceNames('EN'),
            $npcClubGenerationService->getRemainingPlaceNames($country),
        );
        $suffixes = $npcClubGenerationService->getSuffixes($country) ?: $npcClubGenerationService->getSuffixes('EN');

        $locale = Country::tryFrom($country)?->locale() ?? 'en_GB';

        return $this->json([
            'country'  => $country,
            'cities'   => $this->sortAlphabetically($cities, $locale),
            'suffixes' => $this->sortAlphabetically($suffixes, $locale),
            // Names already used by NPC clubs in this country. The client hides
            // the city/suffix combinations that would collide, so the user
            // cannot pick a name that already exists in their pyramid.
            // Deliberately unsorted — this is a matching set, not a display list.
            'takenNames' => $npcClubRepo->findNamesByCountry($country),
        ]);
    }

    /** @param string[] $values @return string[] */
    private function sortAlphabetically(array $values, string $locale): array
    {
        $collator = \Collator::create($locale);
        if ($collator !== null) {
            $collator->sort($values);
        } else {
            sort($values, SORT_STRING | SORT_FLAG_CASE);
        }

        return array_values($values);
    }

    #[Route('/initialize', name: 'api_club_initialize', methods: ['POST'])]
    #[IsGranted('ROLE_CLUB')]
    public function initialize(
        #[MapRequestPayload] ClubInitRequest $dto,
        ClubInitializationService $service,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $club = $service->initializeClub($user, $dto->clubName, $dto->country);
        } catch (ClubNameTakenException $e) {
            // Distinct from the 409 below, which means "this user already has a club".
            return $this->json(
                ['error' => 'club_name_taken', 'message' => $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (InvalidCountryCodeException $e) {
            return $this->json(
                ['error' => 'invalid_country_code', 'message' => $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json([
            'id'            => $club->getId()->toRfc4122(),
            'name'          => $club->getName(),
            'starterBundle' => $service->getStarterBundle(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/check', name: 'api_club_check', methods: ['GET'])]
    #[IsGranted('ROLE_CLUB')]
    public function check(): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);

        if ($club === null) {
            return $this->json(['exists' => false, 'reason' => 'club_not_found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['exists' => true, 'clubId' => $club->getId()->toRfc4122()]);
    }

    /**
     * Every club owned by the authenticated account (a user can own more than one — one per
     * save slot, see ClubResolver's docblock), each with a brief identity + last-sync summary.
     * Unlike every other method on this controller, this deliberately does NOT resolve to a
     * single club via ClubResolver — it's the one place a caller is meant to see all of them
     * at once, e.g. a save-slot picker. See docs/api/club-list.md.
     */
    #[Route('/all', name: 'api_clubs_all', methods: ['GET'])]
    #[IsGranted('ROLE_CLUB')]
    public function all(ClubRepository $clubRepository, SyncRecordRepository $syncRecordRepository): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $clubs = array_map(
            fn (Club $club) => $this->serializeClubSummary($club, $syncRecordRepository->findLatestValid($club)),
            $clubRepository->findAllByUser($user),
        );

        return $this->json(['clubs' => $clubs]);
    }

    /** @return array<string, mixed> */
    private function serializeClubSummary(Club $club, ?SyncRecord $latestValidSync): array
    {
        $payload = $latestValidSync?->getPayload() ?? [];

        return [
            'id'                  => $club->getId()->toRfc4122(),
            'name'                => $club->getName(),
            'country'             => $club->getCountry(),
            'abbreviation'        => $club->getAbbreviation(),
            'reputation'          => $club->getReputation(),
            'balance'             => $club->getBalance(),
            'hasDebt'             => $club->hasDebt(),
            'totalCareerEarnings' => $club->getTotalCareerEarnings(),
            'hallOfFamePoints'    => $club->getHallOfFamePoints(),
            'homeKitConfig'       => $club->getHomeKitConfig(),
            'awayKitConfig'       => $club->getAwayKitConfig(),
            'badgeConfig'         => $club->getBadgeConfig(),
            // Week/date are the club's own authoritative last-sync columns (updated on every
            // accepted sync, rollback or not); leaguePosition/form come from the latest VALID
            // sync's payload specifically — same split ClubCrudController's admin profile uses.
            'lastSync'            => $club->getLastSyncedAt() === null ? null : [
                'weekNumber'     => $club->getLastSyncedWeek(),
                'syncedAt'       => $club->getLastSyncedAt()->format(\DateTimeInterface::ATOM),
                'leaguePosition' => $payload['leaguePosition'] ?? null,
                'form'           => $payload['form'] ?? [],
            ],
        ];
    }

    #[Route('/status', name: 'api_club_status', methods: ['GET'])]
    #[IsGranted('ROLE_CLUB')]
    public function status(): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $club = $this->clubResolver->resolveFromRequest($user);

        if ($club === null) {
            return $this->json(['error' => 'No club found.'], Response::HTTP_NOT_FOUND);
        }

        $activeInvestorCount = $club->getInvestors()
            ->filter(fn (Investor $i) => $i->isActive())
            ->count();

        return $this->json([
            'id'                  => $club->getId()->toRfc4122(),
            'name'                => $club->getName(),
            'abbreviation'        => $club->getAbbreviation(),
            'balance'             => $club->getBalance(),
            'hasDebt'             => $club->hasDebt(),
            'reputation'          => $club->getReputation(),
            'weekNumber'          => $club->getLastSyncedWeek(),
            'totalCareerEarnings' => $club->getTotalCareerEarnings(),
            'hallOfFamePoints'    => $club->getHallOfFamePoints(),
            'activeSponsors'      => $club->getActiveSponsors()->count(),
            'activeInvestors'     => $activeInvestorCount,
            'tutorialCompletedAt' => $club->getTutorialCompletedAt()?->format(\DateTimeInterface::ATOM),
            // Kit + badge identity — set via GET/POST /api/club/kit-identity,
            // mirrored here for convenience since this is the general club-state
            // poll. See docs/api/club-kit-identity.md.
            'homeKitConfig'       => $club->getHomeKitConfig(),
            'awayKitConfig'       => $club->getAwayKitConfig(),
            'badgeConfig'         => $club->getBadgeConfig(),
        ]);
    }
}
