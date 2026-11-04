<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Location\CountryProfile;
use App\Entity\Location\Location;
use App\Entity\User;
use App\Enum\Country;
use App\Enum\Location\EnrichmentStatus;
use App\Enum\Location\LocationStatus;
use App\Repository\Location\CountryProfileRepository;
use App\Repository\Location\LandPlotRepository;
use App\Repository\Location\LocationRepository;
use App\Repository\Location\LocationRivalRepository;
use App\Repository\NpcClubRepository;
use App\Repository\UserLedgerRepository;
use App\Service\ClubNameNormalizer;
use App\Service\Location\Enrichment\LocationEnrichmentService;
use App\Service\Location\LocationCapacityService;
use App\Service\Location\StadiumEstimateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The land catalogue — which towns exist, what they cost, and who already owns them.
 *
 * PUBLIC. A player browses land before signing in, and the list carries no per-account data by
 * default. The one exception is the affordability variant below, which is opt-in precisely
 * because it changes that.
 *
 * THREE RULES THIS CONTROLLER EXISTS TO ENFORCE.
 *
 * 1. `country` IS REQUIRED on the list. Thousands of rows span 70-odd countries, there is no
 *    caller for an unfiltered list, and requiring it means idx_location_country_status_land
 *    serves every query here. Omitting it is a 400, not a silent "all countries".
 *
 * 2. ONLY THE DETAIL ENDPOINT TRIGGERS ENRICHMENT. Enrichment costs a billed external API call
 *    per location. A list page of 200 un-enriched towns would queue 200 of them, so a crawler
 *    or a player scrolling a region could drain the budget in a few requests. Batch enrichment
 *    is an admin action, deliberately. The list dispatches nothing, ever.
 *
 * 3. AN UN-ENRICHED LOCATION IS STILL RETURNED — 200, with `lat`, `lng` and `landPence` NULL
 *    and `enrichmentStatus` saying why. Not a 404, not an error. Clients must treat those three
 *    fields as nullable; `enrichmentStatus` is the loading signal. Half-built COUNTRIES are kept
 *    out wholesale by the country_profile status gate instead, which is the right granularity:
 *    a single pending row inside a live country is a transient state, not a broken country.
 *
 * CACHING SPLITS THREE WAYS, and the split is load-bearing:
 *   - the plain list is public, 60s — shared, identical for everyone;
 *   - the affordability variant is private and uncacheable, because it reads the caller's
 *     balance and a shared cache would serve one player's affordability to another;
 *   - a detail response whose status is pending/queued is private and uncacheable, or the
 *     client polls a cached "queued" for ever and never sees the enrichment land.
 */
#[Route('/api/locations')]
class LocationController extends AbstractController
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;
    private const LIST_TTL      = 60;
    private const FACETS_TTL    = 300;

    #[Route('', name: 'api_locations_list', methods: ['GET'])]
    public function list(
        Request $request,
        LocationRepository $locations,
        CountryProfileRepository $countries,
        LandPlotRepository $plots,
        UserLedgerRepository $ledger,
    ): JsonResponse {
        $countryCode = strtoupper(trim((string) $request->query->get('country', '')));

        if ($countryCode === '') {
            return $this->json([
                'error'   => 'country_required',
                'message' => 'Pass ?country=XX. There is no unfiltered location list.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $country = $countries->findByCode($countryCode);

        if ($country === null) {
            return $this->json([
                'error'   => 'unknown_country',
                'message' => sprintf('No country profile for "%s".', $countryCode),
            ], Response::HTTP_NOT_FOUND);
        }

        $status = $this->statusFrom($request);

        if ($status === null) {
            return $this->json([
                'error'   => 'invalid_status',
                'message' => 'status must be one of: active, retired.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // A country that is not live reports itself as empty rather than 404ing. The code is
        // valid and will serve locations later; `countryStatus` is what tells an operator why
        // the list is empty, instead of leaving them to guess at a missing filter.
        if (!$country->isLive()) {
            return $this->listResponse([
                'country'     => $this->countryMeta($country, 0),
                'locations'   => [],
                'nextCursor'  => null,
            ], affordability: false);
        }

        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query->get('limit', (string) self::DEFAULT_LIMIT)));
        $sort  = $request->query->get('sort') === LocationRepository::SORT_PRICE
            ? LocationRepository::SORT_PRICE
            : LocationRepository::SORT_TOWN;

        $cursor = $request->query->get('cursor');
        $cursorKey = $cursorId = null;

        if ($cursor !== null && $cursor !== '') {
            $decoded = $this->decodeCursor((string) $cursor);

            if ($decoded === null) {
                return $this->json([
                    'error'   => 'invalid_cursor',
                    'message' => 'The cursor is not one this endpoint issued.',
                ], Response::HTTP_BAD_REQUEST);
            }

            [$cursorSort, $cursorKey, $cursorId] = $decoded;

            // The cursor carries the ordering it was issued for. Paging a price-sorted cursor
            // with ?sort=town would compare a price against a town name and return a quietly
            // wrong page, so it is a 400 rather than a best effort.
            if ($cursorSort !== $sort) {
                return $this->json([
                    'error'   => 'cursor_sort_mismatch',
                    'message' => sprintf('That cursor was issued for sort=%s.', $cursorSort),
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        $maxLandPence = $request->query->get('maxLandPence');
        $search       = trim((string) $request->query->get('q', ''));

        $rows = $locations->findForList(
            countryCode: $countryCode,
            status: $status,
            limit: $limit,
            regions: $this->regionsFrom($request),
            maxLandPence: $maxLandPence !== null && $maxLandPence !== '' ? max(0, (int) $maxLandPence) : null,
            normalisedSearch: $search !== '' ? ClubNameNormalizer::normalize($search) : null,
            sort: $sort,
            cursorKey: $cursorKey,
            cursorId: $cursorId,
        );

        $hasMore = count($rows) > $limit;
        $page    = $hasMore ? array_slice($rows, 0, $limit) : $rows;

        // Opt-in, because it makes the response per-user and therefore uncacheable. A signed-out
        // caller asking for it gets the flags computed against a zero balance, which is honest:
        // nothing is affordable until you have credit.
        $wantsAffordability = $request->query->getBoolean('affordability')
            || $request->query->getBoolean('affordable');

        $balancePence = 0;
        $user         = $this->getUser();

        if ($wantsAffordability && $user instanceof User) {
            $balancePence = $ledger->getCurrentBalance($user);
        }

        // One query for every plot and claim on the page — see
        // LandPlotRepository::findActivePlotsWithClaimsByLocationIds().
        $plotsByLocation = $plots->findActivePlotsWithClaimsByLocationIds(
            array_map(static fn (array $r): string => (string) $r['id'], $page),
        );

        $serialised = array_map(
            fn (array $row): array => $this->presentListRow(
                $row,
                $plotsByLocation[(string) $row['id']] ?? [],
                $wantsAffordability,
                $balancePence,
            ),
            $page,
        );

        // AFFORDABILITY FILTER, applied after the page is built rather than in SQL. It depends on
        // the caller's balance, which is not a column, so filtering it in the query would make
        // the keyset cursor depend on the balance too — and a cursor that stops being valid when
        // the player's credit changes is worse than a short page.
        if ($request->query->getBoolean('affordable')) {
            $serialised = array_values(array_filter(
                $serialised,
                static fn (array $l): bool => $l['landAffordable'] === true,
            ));
        }

        $last = $page !== [] ? $page[count($page) - 1] : null;

        return $this->listResponse([
            'country'    => $this->countryMeta($country, null),
            'locations'  => $serialised,
            'nextCursor' => $hasMore && $last !== null ? $this->encodeCursor($last, $sort) : null,
        ], $wantsAffordability);
    }

    #[Route('/facets', name: 'api_locations_facets', methods: ['GET'])]
    public function facets(
        Request $request,
        LocationRepository $locations,
        CountryProfileRepository $countries,
    ): JsonResponse {
        $countryCode = strtoupper(trim((string) $request->query->get('country', '')));

        if ($countryCode === '') {
            return $this->json([
                'error'   => 'country_required',
                'message' => 'Pass ?country=XX.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $country = $countries->findByCode($countryCode);

        if ($country === null) {
            return $this->json([
                'error'   => 'unknown_country',
                'message' => sprintf('No country profile for "%s".', $countryCode),
            ], Response::HTTP_NOT_FOUND);
        }

        $facets = $country->isLive()
            ? $locations->facetsFor($countryCode)
            : ['regions' => [], 'landPenceMin' => null, 'landPenceMax' => null, 'pricedCount' => 0, 'totalCount' => 0];

        return $this->json(['country' => $this->countryMeta($country, $facets['totalCount'])] + $facets)
            ->setPublic()
            ->setMaxAge(self::FACETS_TTL);
    }

    /**
     * One location in full — and the only endpoint that may queue enrichment.
     *
     * NOT IDEMPOTENT, in a small and deliberate way: a GET of an un-enriched location takes the
     * enrichment lease and dispatches a message, so it writes. That is the lazy-loading contract
     * the feature is built on (see rule 2 in the class docblock) and is why the response is
     * uncacheable while the status is pending or queued.
     */
    #[Route('/{id}', name: 'api_locations_detail', methods: ['GET'])]
    public function detail(
        string $id,
        Request $request,
        LocationRepository $locations,
        LandPlotRepository $plots,
        LocationRivalRepository $rivals,
        StadiumEstimateService $estimates,
        LocationEnrichmentService $enrichment,
        UserLedgerRepository $ledger,
        NpcClubRepository $npcClubs,
        LocationCapacityService $capacity,
    ): JsonResponse {
        $location = $locations->find($id);

        if ($location === null) {
            return $this->json(['error' => 'location_not_found'], Response::HTTP_NOT_FOUND);
        }

        // Only the caller that wins the lease dispatches; everyone else just reports the status.
        // Either way the response says "queued", so a herd of readers sees one consistent
        // answer and costs one API call.
        //
        // THE RETURN VALUE IS USED, not discarded, and it has to be: the lease is taken by a raw
        // conditional UPDATE, so the hydrated entity in this request still carries the status it
        // had BEFORE the claim. Reading the status off the entity would report "pending" on the
        // very read that queued it — and a client watching for "queued" would re-request for
        // ever, which is a billed API call each time it wins the lease.
        $justQueued = $enrichment->queueForRead($location);

        $effectiveStatus = $justQueued
            ? EnrichmentStatus::QUEUED
            : $location->getEnrichmentStatus();

        $plotRows = $plots->findActivePlotsWithClaimsByLocationIds([(string) $location->getId()]);
        $estimate = $estimates->currentFor($location);

        $balancePence = 0;
        $user         = $this->getUser();
        $wantsAffordability = $request->query->getBoolean('affordability');

        if ($wantsAffordability && $user instanceof User) {
            $balancePence = $ledger->getCurrentBalance($user);
        }

        $payload = $this->presentDetail(
            $location,
            $plotRows[(string) $location->getId()] ?? [],
            $wantsAffordability,
            $balancePence,
        );

        $payload['stadiumEstimate'] = $estimate !== null
            ? [
                'pence'        => $estimate->getPence(),
                'modelVersion' => $estimate->getModelVersion(),
                'validUntil'   => $estimate->getValidUntil()->format(\DateTimeInterface::ATOM),
            ]
            // NULL is a legitimate pre-enrichment state: a stadium cannot be priced without a
            // population and a land value.
            : null;

        // RIVALS, CLUBS AND RIVAL CLUBS ARE ALL DETAIL-ONLY. Four rows per location across a
        // 200-row page is 800 rows for a list nobody reads them from — stated as a contract so
        // none of them is "optimised" into the list later.
        $payload['enrichmentStatus'] = $effectiveStatus->value;

        $rivalRows = $rivals->findForLocation($location);

        // THE CLUBS ALREADY HERE, and the clubs at this town's nearest rivals. Both are what a
        // player wants before buying: an empty town and one with three established clubs are
        // very different propositions at the same price — and a town with clubs cannot be
        // bought at all (see `claimable` below).
        //
        // ONE QUERY for both, by asking for this location and its rivals together; a per-town
        // lookup would be five queries on a four-rival town.
        $clubsByLocation = $npcClubs->findByLocations(array_merge(
            [(string) $location->getId()],
            array_map(
                static fn ($rival): string => (string) $rival->getRivalLocation()->getId(),
                $rivalRows,
            ),
        ));

        $payload['clubs'] = $clubsByLocation[(string) $location->getId()] ?? [];

        $capacityHere = $capacity->forLocation((string) $location->getId());

        // The same arithmetic POST /api/plots/{id}/hold applies, reported before the player
        // tries rather than after — and with the working, because "sold out" invites the
        // question "according to what?".
        $payload['capacity'] = [
            'plots'        => $capacityHere['plots'],
            'clubs'        => $capacityHere['npcClubs'],
            'takenByClubs' => $capacityHere['npcOccupied'],
            'claimed'      => $capacityHere['claimed'],
            'available'    => $capacityHere['available'],
        ];
        $payload['claimable']       = !$capacityHere['soldOut'];
        $payload['claimableReason'] = $capacityHere['soldOut'] ? 'town_sold_out' : null;

        $payload['rivalClubs'] = array_map(
            static fn ($rival): array => [
                'locationId' => (string) $rival->getRivalLocation()->getId(),
                'town'       => $rival->getRivalLocation()->getTown(),
                'distanceKm' => $rival->getDistanceKm(),
                'clubs'      => $clubsByLocation[(string) $rival->getRivalLocation()->getId()] ?? [],
            ],
            $rivalRows,
        );

        $payload['rivals'] = array_map(
            static fn ($rival): array => [
                'locationId' => (string) $rival->getRivalLocation()->getId(),
                'town'       => $rival->getRivalLocation()->getTown(),
                'rank'       => $rival->getRank(),
                // Stored in km; the client converts per locale using country.distanceUnit.
                'distanceKm' => $rival->getDistanceKm(),
            ],
            $rivalRows,
        );

        $response = $this->json($payload);

        $inFlight = in_array(
            $effectiveStatus,
            [EnrichmentStatus::PENDING, EnrichmentStatus::QUEUED],
            true,
        );

        if ($inFlight || $wantsAffordability) {
            $response->setPrivate()->setMaxAge(0);
        } else {
            $response->setPublic()->setMaxAge(self::LIST_TTL);
        }

        return $response;
    }

    /** @param array<string, mixed> $payload */
    private function listResponse(array $payload, bool $affordability): JsonResponse
    {
        $response = $this->json($payload);

        return $affordability
            ? $response->setPrivate()->setMaxAge(0)
            : $response->setPublic()->setMaxAge(self::LIST_TTL);
    }

    /**
     * @param array<string, mixed>                 $row
     * @param list<array<string, mixed>>           $plotRows
     *
     * @return array<string, mixed>
     */
    private function presentListRow(array $row, array $plotRows, bool $affordability, int $balancePence): array
    {
        $landPence = $row['landPence'] !== null ? (int) $row['landPence'] : null;
        $primary   = $plotRows[0] ?? null;

        $out = [
            'id'          => (string) $row['id'],
            'slug'        => $row['slug'],
            'town'        => $row['town'],
            'localisedNames' => $row['localisedNames'],
            'region'      => $row['region'],
            // NULLABLE UNTIL ENRICHED. See rule 3.
            'lat'         => $row['lat'] !== null ? (float) $row['lat'] : null,
            'lng'         => $row['lng'] !== null ? (float) $row['lng'] : null,
            'population'  => $row['population'] !== null ? (int) $row['population'] : null,
            'populationSource' => $row['populationSource'],
            'populationAsOf'   => $row['populationAsOf']?->format('Y-m-d'),
            'landPence'   => $landPence,
            'landValuedAt' => $row['landValuedAt']?->format(\DateTimeInterface::ATOM),
            // Provenance, because the app tells players land is "priced from real local values".
            'landSource'  => $row['landSource'],
            'isCapital'   => (bool) $row['isCapital'],
            'citySize'    => self::enumValue($row['citySize']),
            'status'      => self::enumValue($row['status']),
            'enrichmentStatus' => self::enumValue($row['enrichmentStatus']),
            'plotCount'   => count($plotRows),
            'plotId'      => $primary !== null ? $primary['plotId'] : null,
            // TWO SEPARATE FACTS. isClaimed says whether the plot is for sale; claimedBy is the
            // owner's display name, which is legitimately NULL when the land was bought before
            // a club was founded. A client must gate "buy" on isClaimed, never on claimedBy.
            'isClaimed'   => $primary !== null && $primary['isClaimed'],
            // From the frozen club_name_at_claim, so it survives the club being deleted.
            'claimedBy'   => $primary !== null ? $primary['claimedBy'] : null,
        ];

        if ($affordability) {
            // AGAINST LAND ONLY. The stadium is a separate, later purchase, so this matches what
            // POST /api/plots/{id}/claim actually charges. An unpriced town is not affordable —
            // it is unknown, and reporting it as affordable would promise a price we don't have.
            $out['landAffordable'] = $landPence !== null && $landPence <= $balancePence;
            $out['shortfallPence'] = $landPence !== null ? max(0, $landPence - $balancePence) : null;
            $out['balancePence']   = $balancePence;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $plotRows
     *
     * @return array<string, mixed>
     */
    private function presentDetail(
        Location $location,
        array $plotRows,
        bool $affordability,
        int $balancePence,
    ): array {
        $country = $location->getCountry();

        $out = [
            'id'               => (string) $location->getId(),
            'slug'             => $location->getSlug(),
            'town'             => $location->getTown(),
            'localisedNames'   => $location->getLocalisedNames(),
            'region'           => $location->getRegion(),
            'countryCode'      => $location->getCountryCode(),
            'country'          => $this->countryMeta($country, null),
            'lat'              => $location->getLat(),
            'lng'              => $location->getLng(),
            'population'       => $location->getPopulation(),
            'populationSource' => $location->getPopulationSource(),
            'populationAsOf'   => $location->getPopulationAsOf()?->format('Y-m-d'),
            'landPence'        => $location->getLandPence(),
            'landValuedAt'     => $location->getLandValuedAt()?->format(\DateTimeInterface::ATOM),
            'landSource'       => $location->getLandSource(),
            'isCapital'        => $location->isCapital(),
            'citySize'         => $location->getCitySize()->value,
            'status'           => $location->getStatus()->value,
            'enrichmentStatus' => $location->getEnrichmentStatus()->value,
            'plots'            => array_map(
                static fn (array $p): array => [
                    'plotId'     => $p['plotId'],
                    'plotNumber' => $p['plotNumber'],
                    'landPence'  => $p['landPenceOverride'],
                    'isClaimed'  => $p['isClaimed'],
                    'claimedBy'  => $p['claimedBy'],
                ],
                $plotRows,
            ),
        ];

        if ($affordability) {
            $landPence = $location->getLandPence();

            $out['landAffordable'] = $landPence !== null && $landPence <= $balancePence;
            $out['shortfallPence'] = $landPence !== null ? max(0, $landPence - $balancePence) : null;
            $out['balancePence']   = $balancePence;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function countryMeta(CountryProfile $country, ?int $locationCount): array
    {
        $enumCase = Country::tryFrom($country->getCode());

        $meta = [
            'code'   => $country->getCode(),
            'name'   => $country->getName(),
            // `code` ABOVE IS A FOOTBALL CODE, NOT ISO. EN is England, which has no ISO code of
            // its own; SC and NI collide with Seychelles and Nicaragua. isoAlpha2 is the real
            // one (GB for all four home nations), null for Kosovo where none exists. A client
            // needing a genuine country code reads isoAlpha2; one matching our own data reads
            // `code`. isIsoCode says whether they happen to agree.
            'isoAlpha2'    => $enumCase?->isoAlpha2(),
            'isIsoCode'    => $enumCase?->isIsoCode(),
            'isoAlpha3'    => $country->getIsoAlpha3(),
            'continent'    => $country->getContinent(),
            'currencyCode' => $country->getCurrencyCode(),
            'distanceUnit' => $country->getDistanceUnit()->value,
            'status'       => $country->getStatus()->value,
        ];

        if ($locationCount !== null) {
            $meta['locationCount'] = $locationCount;
        }

        return $meta;
    }

    /**
     * The backing value of an enum column read through getArrayResult().
     *
     * Defensive on purpose: scalar hydration of an enumType column hands back the enum instance,
     * but a plain projection of the same column can hand back the raw string, and which one you
     * get is not worth depending on from a response serialiser.
     */
    private static function enumValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    private function statusFrom(Request $request): ?LocationStatus
    {
        $raw = $request->query->get('status');

        if ($raw === null || $raw === '') {
            return LocationStatus::ACTIVE;
        }

        return LocationStatus::tryFrom((string) $raw);
    }

    /** @return list<string>|null */
    private function regionsFrom(Request $request): ?array
    {
        $raw = trim((string) $request->query->get('region', ''));

        if ($raw === '') {
            return null;
        }

        // Comma-separated, so one request can cover "the north" without N round trips.
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $r): bool => $r !== ''));
    }

    /**
     * Opaque cursor naming the last row of a page: its sort key plus its id as the tie-break.
     *
     * The sort name is encoded INTO the cursor and checked on the way back, so a client that
     * pages with a cursor from one ordering and a different `sort` parameter gets a 400 rather
     * than a quietly wrong page.
     *
     * @param array<string, mixed> $row
     */
    private function encodeCursor(array $row, string $sort): string
    {
        $key = $sort === LocationRepository::SORT_PRICE
            ? (string) ($row['landPence'] !== null ? (int) $row['landPence'] : LocationRepository::UNPRICED_SORT_KEY)
            : (string) $row['town'];

        return rtrim(strtr(base64_encode($sort . '|' . $key . '|' . $row['id']), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    private function decodeCursor(string $cursor): ?array
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($raw === false) {
            return null;
        }

        $parts = explode('|', $raw, 3);

        if (count($parts) !== 3 || $parts[2] === '' || !\Symfony\Component\Uid\Uuid::isValid($parts[2])) {
            return null;
        }

        return [$parts[0], $parts[1], $parts[2]];
    }
}
