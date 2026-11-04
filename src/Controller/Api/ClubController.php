<?php

namespace App\Controller\Api;

use App\Dto\ClubInitRequest;
use App\Entity\Club;
use App\Entity\Investor;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Enum\Country;
use App\Exception\ClubMismatchException;
use App\Exception\ClubNameTakenException;
use App\Exception\InvalidCountryCodeException;
use App\Exception\Location\PlotUnavailableException;
use App\Repository\ClubRepository;
use App\Repository\GameConfigRepository;
use App\Repository\NpcClubRepository;
use App\Repository\SyncRecordRepository;
use App\Service\ClubInitializationService;
use App\Service\DebugLoggingResolver;
use App\Service\Location\LocationCatalogueService;
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

    public function __construct(
        private readonly ClubResolver $clubResolver,
        private readonly GameConfigRepository $gameConfigRepository,
    ) {}

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
        $cities   = $this->cityOptionsFor($country, $npcClubGenerationService);
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

    /**
     * The club-name city list, which must never come back empty.
     *
     * Curated town names now live in the `location` table, so this endpoint gained a dependency
     * it did not have before: an unseeded table means no towns. That broke the England
     * fallback for unsupported countries outright — `?country=XX` returned an empty city list,
     * and this endpoint is what the client offers at club creation, so an empty list is a dead
     * end rather than a degraded experience.
     *
     * Hence an explicit chain rather than a single `?:`. The terminal source is
     * CountryContentRegistry's bare-name list, reached through getRemainingPlaceNames(), which
     * is still authored in PHP and is not going anywhere: those ~4,200 names exist precisely to
     * widen this picker, and they carry no geography, which is why they were never migrated.
     * Using them here is not the dual-source drift the migration set out to remove — world
     * GENERATION reads the database and only the database.
     *
     * @return string[]
     */
    private function cityOptionsFor(string $country, NpcClubGenerationService $generator): array
    {
        // 1. The country's own curated towns (database) plus its wider name list (PHP const).
        $cities = array_merge(
            $generator->getPlaceNames($country),
            $generator->getRemainingPlaceNames($country),
        );

        if ($cities !== []) {
            return $cities;
        }

        // 2. Unsupported or unseeded country: fall back to England, both halves.
        $cities = array_merge(
            $generator->getPlaceNames('EN'),
            $generator->getRemainingPlaceNames('EN'),
        );

        if ($cities !== []) {
            return $cities;
        }

        // 3. Nothing at all — only reachable with an unseeded database AND a registry with no
        //    England entry, which should be impossible. Returning the generic placeholder town
        //    names keeps the picker usable instead of handing the client a dead end; the
        //    operational problem behind it is reported by app:country:check.
        return array_column(LocationCatalogueService::PLACEHOLDER_PLACES, 'name');
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
            $club = $service->initializeClub($user, $dto->clubName, $dto->country, $dto->locationId);
        } catch (PlotUnavailableException $e) {
            // Founding on a plot the caller does not own is a 403, not a 409: it is an
            // authorisation answer about someone else's land, and the message deliberately does
            // not say whether anyone owns it. A claim that already has a club IS a conflict.
            return $this->json(
                ['error' => $e->reason, 'message' => $e->getMessage()] + $e->context,
                $e->reason === PlotUnavailableException::NOT_OWNED
                    ? Response::HTTP_FORBIDDEN
                    : Response::HTTP_CONFLICT,
            );
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
            // Echoed so the client can confirm the club landed on the plot it paid for, rather
            // than inferring it from the absence of an error. NULL when founded without one.
            'homeLocationId' => $club->getHomeLocation() !== null
                ? (string) $club->getHomeLocation()->getId()
                : null,
            'starterBundle' => $service->getStarterBundle(),
        ], Response::HTTP_CREATED);
    }

    /**
     * Deliberately authentication-only, unlike every other method here — the 404 below IS the
     * answer for an account with no club, and a role check in front of it makes that branch
     * unreachable for exactly the callers who need it. The client treats 404 as "no club yet"
     * and any other failure as "the server refused this account", so a 403 here reads as a
     * dead account rather than an empty one. security.yaml carries the matching exception
     * ahead of its ^/api/club/ rule; relaxing only one of the two changes nothing.
     * See docs/api/role-club-403.md.
     */
    #[Route('/check', name: 'api_club_check', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function check(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $club = $this->clubResolver->resolveFromRequest($user);
        } catch (ClubMismatchException) {
            // An X-Club-Id this account does not own is, on THIS endpoint, just the answer no:
            // it is asking whether the account has that club. Everywhere else the exception's
            // own 403 is right (it guards a write against landing on another save's club), but
            // here it would hand the client the same "the server refused this account" reading
            // the ROLE_CLUB gate used to, for a client holding nothing worse than a stale id —
            // which is precisely the state a half-finished club creation leaves behind.
            // Discloses nothing: ClubResolver throws identically for an unknown id and another
            // user's, so the body below cannot distinguish the two either.
            return $this->json(['exists' => false, 'reason' => 'club_not_owned'], Response::HTTP_NOT_FOUND);
        }

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
            // Mirrored from GET /api/game-config, which carries the same key with the same
            // meaning (DebugLoggingResolver owns the one definition both go through).
            // It is repeated here because that endpoint is public: an anonymous cold-boot
            // fetch can only ever see the GLOBAL half of the flag, so a per-account override
            // would appear to do nothing until the client happened to refetch game-config
            // with its token. This endpoint is ROLE_CLUB, so the override always applies —
            // which makes this the reliable place for a client to read it post-sign-in.
            'debugLoggingEnabled' => DebugLoggingResolver::resolve(
                $this->gameConfigRepository->getConfig(),
                $user,
            ),
        ]);
    }
}
