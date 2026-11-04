<?php

declare(strict_types=1);

namespace App\Repository\Location;

use App\Entity\Location\Location;
use App\Enum\Location\EnrichmentStatus;
use App\Enum\Location\LocationStatus;
use App\Service\Location\Haversine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Location>
 *
 * Two things to know before adding a method here.
 *
 * 1. ORM 3 removed PARTIAL hydration, so every mapped column — ~25 of them, including a
 *    jsonb — is decoded on every read of a hydrated row. Anything that returns more than a
 *    handful of locations must project explicit columns via getArrayResult(), not hydrate
 *    entities. findForList() and findForGeneration() both do.
 *
 * 2. The enrichment claim is raw DBAL on purpose. A UniqueConstraintViolation or a failed
 *    flush() closes the shared EntityManager, and the claim runs on a public GET — the
 *    LeaderboardPeriodCloseService::claim() reasoning applies verbatim.
 */
class LocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Location::class);
    }

    /**
     * Take the enrichment lease for one location.
     *
     * Returns true for exactly ONE caller when many arrive at once, and only that caller may
     * dispatch the enrichment message. This is the whole thundering-herd defence: fifty
     * simultaneous viewers of an un-enriched town produce one external API call.
     *
     * A row whose claim is older than the lease is reclaimable, which is what stops a worker
     * dying mid-call from pinning a location at QUEUED for ever.
     */
    public function claimForEnrichment(Location $location, \DateTimeImmutable $now): bool
    {
        $leaseExpiry = $now->modify('-' . Location::CLAIM_LEASE_SECONDS . ' seconds');

        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE location
                SET enrichment_status = :queued,
                    enrichment_claimed_at = :now
              WHERE id = :id
                AND enrichment_attempts < :maxAttempts
                AND (
                      enrichment_status = :pending
                   OR (enrichment_status = :queued AND enrichment_claimed_at < :leaseExpiry)
                )',
            [
                'queued'      => EnrichmentStatus::QUEUED->value,
                'pending'     => EnrichmentStatus::PENDING->value,
                'now'         => $now,
                'leaseExpiry' => $leaseExpiry,
                'id'          => (string) $location->getId(),
                'maxAttempts' => Location::MAX_ENRICHMENT_ATTEMPTS,
            ],
            [
                'now'         => 'datetime_immutable',
                'leaseExpiry' => 'datetime_immutable',
                'maxAttempts' => ParameterType::INTEGER,
            ],
        );

        return $affected === 1;
    }

    /**
     * Claim up to $limit rows for an admin sweep, returning the ids actually claimed.
     *
     * Set-based rather than a loop of claimForEnrichment() calls: one statement, and the
     * RETURNING clause means the caller dispatches exactly the rows it won.
     *
     * @param list<EnrichmentStatus> $statuses
     *
     * @return list<string> claimed location ids
     */
    public function claimBatchForEnrichment(
        array $statuses,
        \DateTimeImmutable $now,
        int $limit,
        ?string $countryCode = null,
    ): array {
        if ($statuses === [] || $limit < 1) {
            return [];
        }

        // A CTE, NOT `WHERE id IN (SELECT ... LIMIT n FOR UPDATE SKIP LOCKED)`.
        //
        // That earlier shape silently ignored its limit. PostgreSQL may plan `IN (subquery)` as
        // a Nested Loop Semi Join, which RE-EXECUTES the inner query once per candidate outer
        // row — and because the inner query carries FOR UPDATE SKIP LOCKED, each re-execution
        // skips the rows the previous one locked and returns a fresh batch. The LIMIT therefore
        // bounds each execution rather than the statement, and a sweep asked for 4 rows claimed
        // all 10.
        //
        // It was invisible in isolation and only appeared in a full suite run, because the plan
        // the optimiser picks depends on the table's statistics: with a nearly empty `location`
        // table it chose a plan that happened to give the right answer. That is the worst
        // possible failure mode for this particular query — every claimed row is a BILLED API
        // call, so an ignored `--limit` means an unbounded spend, and the one environment where
        // it looks correct is a developer's empty database.
        //
        // Wrapping the selection in a CTE fixes it: PostgreSQL materialises a CTE containing
        // FOR UPDATE exactly once, so the lock set and the LIMIT are both evaluated a single
        // time. This is the canonical claim-N-rows pattern.
        //
        // The limit is interpolated rather than bound purely to keep it clear of DBAL's
        // named-to-positional rewrite alongside the ArrayParameterType placeholder below; the
        // int cast is what makes that safe, and there is no string path into this value.
        $safeLimit = max(1, (int) $limit);

        $sql = 'WITH claimed AS (
                    SELECT id
                      FROM location
                     WHERE enrichment_status IN (:statuses)
                       AND enrichment_attempts < :maxAttempts
                       AND status = :active'
            . ($countryCode !== null ? ' AND country_code = :country' : '')
            . '  ORDER BY created_at ASC
                     LIMIT ' . $safeLimit . '
                     FOR UPDATE SKIP LOCKED
                )
                UPDATE location AS l
                   SET enrichment_status = :queued,
                       enrichment_claimed_at = :now
                  FROM claimed c
                 WHERE l.id = c.id
             RETURNING l.id AS id';

        $params = [
            'queued'      => EnrichmentStatus::QUEUED->value,
            'now'         => $now,
            'statuses'    => array_map(static fn (EnrichmentStatus $s): string => $s->value, $statuses),
            'maxAttempts' => Location::MAX_ENRICHMENT_ATTEMPTS,
            'active'      => LocationStatus::ACTIVE->value,
        ];
        $types = [
            'now'         => 'datetime_immutable',
            'statuses'    => \Doctrine\DBAL\ArrayParameterType::STRING,
            'maxAttempts' => ParameterType::INTEGER,
        ];

        if ($countryCode !== null) {
            $params['country'] = strtoupper($countryCode);
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params, $types);

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    /**
     * Claim an explicit list of locations for enrichment, returning the ids actually won.
     *
     * Used by the admin single and subset triggers. Unlike the sweep, this accepts
     * NEEDS_REVIEW as well as PENDING and FAILED: an operator working the review queue is
     * deliberately re-asking about a row a human has looked at, which is exactly the case the
     * lazy read path refuses to do on its own.
     *
     * It still goes through a claim rather than dispatching blind, so a row already queued by
     * the lazy path or by another admin is not enriched — and paid for — twice. It also
     * ignores MAX_ENRICHMENT_ATTEMPTS, because an explicit admin retry is the documented way
     * past that cap.
     *
     * @param list<string> $locationIds
     *
     * @return list<string> the ids this call claimed
     */
    public function claimSpecificForEnrichment(array $locationIds, \DateTimeImmutable $now): array
    {
        if ($locationIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'UPDATE location SET enrichment_status = :queued, enrichment_claimed_at = :now
              WHERE id IN (:ids)
                AND status = :active
                AND enrichment_status IN (:claimable)
           RETURNING id',
            [
                'queued'    => EnrichmentStatus::QUEUED->value,
                'now'       => $now,
                'ids'       => $locationIds,
                'active'    => LocationStatus::ACTIVE->value,
                'claimable' => [
                    EnrichmentStatus::PENDING->value,
                    EnrichmentStatus::FAILED->value,
                    EnrichmentStatus::NEEDS_REVIEW->value,
                ],
            ],
            [
                'now'       => 'datetime_immutable',
                'ids'       => \Doctrine\DBAL\ArrayParameterType::STRING,
                'claimable' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ],
        );

        return array_map(static fn (array $row): string => (string) $row['id'], $rows);
    }

    /**
     * How many locations sit in each enrichment status, optionally for one country.
     *
     * Backs the admin enrichment overview and CountryReadinessService. One GROUP BY off
     * idx_location_enrichment.
     *
     * @return array<string, int> status value => count
     */
    public function countByEnrichmentStatus(?string $countryCode = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->select('l.enrichmentStatus AS status', 'COUNT(l.id) AS total')
            ->groupBy('l.enrichmentStatus');

        if ($countryCode !== null) {
            $qb->where('l.country = :country')->setParameter('country', strtoupper($countryCode));
        }

        $out = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $status = $row['status'];
            $key    = $status instanceof EnrichmentStatus ? $status->value : (string) $status;

            $out[$key] = (int) $row['total'];
        }

        return $out;
    }

    /**
     * The projection NpcClubGenerationService consumes, byte-compatible with what
     * CountryContentRegistry::places() used to return plus a persisted `city_size`.
     *
     * Explicit columns via getArrayResult() — see the class docblock on ORM 3 hydration.
     * Only ACTIVE locations: a retired town must stop producing new NPC clubs.
     *
     * @return list<array{name: string, population_size: int, region: string, is_capital: bool, city_size: string}>
     */
    public function findForGeneration(string $countryCode): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select(
                'l.town AS name',
                'l.population AS population_size',
                'l.region AS region',
                'l.isCapital AS is_capital',
                'l.citySize AS city_size',
            )
            ->where('l.country = :country')
            ->andWhere('l.status = :active')
            ->setParameter('country', strtoupper($countryCode))
            ->setParameter('active', LocationStatus::ACTIVE)
            ->orderBy('l.population', 'DESC')
            ->addOrderBy('l.town', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            $citySize = $row['city_size'];

            return [
                'name'            => (string) $row['name'],
                'population_size' => (int) ($row['population_size'] ?? 0),
                'region'          => (string) $row['region'],
                'is_capital'      => (bool) $row['is_capital'],
                'city_size'       => is_object($citySize) ? $citySize->value : (string) $citySize,
            ];
        }, $rows);
    }

    /**
     * Every active location in a country with its population, for reclassification.
     *
     * @return list<array{id: string, population: int, isCapital: bool}>
     */
    public function findForClassification(string $countryCode): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('l.id AS id', 'l.population AS population', 'l.isCapital AS isCapital')
            ->where('l.country = :country')
            ->andWhere('l.status = :active')
            ->setParameter('country', strtoupper($countryCode))
            ->setParameter('active', LocationStatus::ACTIVE)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'id'         => (string) $row['id'],
            'population' => (int) ($row['population'] ?? 0),
            'isCapital'  => (bool) $row['isCapital'],
        ], $rows);
    }

    /** @return list<Location> */
    public function findByCountry(string $countryCode): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.country = :country')
            ->setParameter('country', strtoupper($countryCode))
            ->orderBy('l.population', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findBySlug(string $countryCode, string $slug): ?Location
    {
        return $this->createQueryBuilder('l')
            ->where('l.country = :country')
            ->andWhere('l.slug = :slug')
            ->setParameter('country', strtoupper($countryCode))
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Candidate neighbours for rival ranking: active locations in the box, nearest first.
     *
     * Bounding box first (idx_location_lat_lng), haversine second — see Haversine's docblock
     * on why there is no PostGIS here. Excludes the origin and anything without coordinates.
     *
     * @return list<array{id: string, distance_km: float}>
     */
    public function findNearest(
        Location $origin,
        float $radiusKm,
        int $limit,
        bool $sameCountryOnly = true,
    ): array {
        if (!$origin->hasCoordinates()) {
            return [];
        }

        $lat = (float) $origin->getLat();
        $lng = (float) $origin->getLng();
        $box = Haversine::boundingBox($lat, $lng, $radiusKm);

        $sql = 'SELECT l.id AS id, ' . Haversine::SQL_DISTANCE_KM . ' AS distance_km
                  FROM location l
                 WHERE l.id <> :originId
                   AND l.status = :active
                   AND l.lat IS NOT NULL AND l.lng IS NOT NULL
                   AND l.lat BETWEEN :minLat AND :maxLat
                   AND l.lng BETWEEN :minLng AND :maxLng'
            . ($sameCountryOnly ? ' AND l.country_code = :country' : '')
            . ' AND ' . Haversine::SQL_DISTANCE_KM . ' <= :radiusKm
              ORDER BY distance_km ASC, l.id ASC
                 LIMIT ' . max(1, (int) $limit);

        $params = [
            'lat'      => $lat,
            'lng'      => $lng,
            'originId' => (string) $origin->getId(),
            'active'   => LocationStatus::ACTIVE->value,
            'minLat'   => $box['minLat'],
            'maxLat'   => $box['maxLat'],
            'minLng'   => $box['minLng'],
            'maxLng'   => $box['maxLng'],
            'radiusKm' => $radiusKm,
        ];
        $types = [];

        if ($sameCountryOnly) {
            $params['country'] = $origin->getCountryCode();
        }

        return array_map(
            static fn (array $row): array => [
                'id'          => (string) $row['id'],
                'distance_km' => (float) $row['distance_km'],
            ],
            $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params, $types),
        );
    }

    /**
     * Mark every location near a point as needing its rivals recomputed.
     *
     * Called when a location GAINS coordinates: its new position changes which towns are its
     * neighbours' nearest four, not just its own. Without this fan-out an early-enriched town
     * keeps a stale rival set for ever, since nothing else would ever touch its row.
     *
     * Deliberately a bounding box with no haversine refinement — over-invalidating is free
     * (the next --stale-only pass just recomputes a few extra rows) while under-invalidating
     * is a silent wrong answer.
     */
    public function invalidateRivalsNear(Location $origin, float $radiusKm): int
    {
        if (!$origin->hasCoordinates()) {
            return 0;
        }

        $box = Haversine::boundingBox((float) $origin->getLat(), (float) $origin->getLng(), $radiusKm);

        return $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE location
                SET rivals_computed_at = NULL
              WHERE rivals_computed_at IS NOT NULL
                AND lat IS NOT NULL AND lng IS NOT NULL
                AND lat BETWEEN :minLat AND :maxLat
                AND lng BETWEEN :minLng AND :maxLng',
            $box,
        );
    }

    /**
     * Stale locations that CANNOT be ranked yet, because they have no coordinates.
     *
     * findWithStaleRivals() deliberately excludes these — ranking them is impossible and
     * returning them would make every pass churn. But the number is the single most useful
     * thing to tell an operator ("1 town ranked, 864 waiting on enrichment"), so it is counted
     * separately rather than inferred from a difference.
     */
    public function countStaleWithoutCoordinates(?string $countryCode): int
    {
        $sql    = "SELECT COUNT(*) FROM location
                    WHERE status = 'active'
                      AND rivals_computed_at IS NULL
                      AND (lat IS NULL OR lng IS NULL)";
        $params = [];

        if ($countryCode !== null) {
            $sql .= ' AND country_code = :country';
            $params['country'] = strtoupper($countryCode);
        }

        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, $params);
    }

    /** Sort key for the price ordering, standing in for a NULL landPence so it sorts last. */
    public const UNPRICED_SORT_KEY = PHP_INT_MAX;

    public const SORT_TOWN  = 'town';
    public const SORT_PRICE = 'price';

    /**
     * One page of locations for the public list endpoint.
     *
     * PROJECTED, NOT HYDRATED, for the reason in this class's docblock: ORM 3 decodes every
     * mapped column of every hydrated row, jsonb included, and a 200-row page would pay for
     * ~25 columns to render about a dozen.
     *
     * KEYSET, NOT OFFSET, on (sort key, id). Two reasons, and the second is the one that
     * actually forced it: a deep page with a region filter makes OFFSET scan and discard
     * everything before it, and — more importantly — an admin correcting a land valuation
     * mid-browse reorders the price-sorted set, so an offset-paged client would silently skip
     * or repeat towns. A keyset cursor names a position rather than a distance.
     *
     * THE PRICE SORT COALESCES NULL TO A SENTINEL rather than relying on NULLS LAST, so the
     * ordering expression has no NULLs in it and the keyset comparison stays a plain two-column
     * predicate. The cost is that idx_location_country_status_land cannot serve the ORDER BY
     * for that sort (it still serves the filters) — accepted deliberately, because a country
     * holds a few hundred to a few thousand rows and sorting that in memory is free. Revisit if
     * the table is ever widened past the ~5,000 rows this feature is bounded at.
     *
     * Fetches limit + 1 so "there is another page" needs no COUNT.
     *
     * @param list<string>|null $regions
     *
     * @return list<array<string, mixed>> up to $limit + 1 rows
     */
    public function findForList(
        string $countryCode,
        LocationStatus $status,
        int $limit,
        ?array $regions = null,
        ?int $maxLandPence = null,
        ?string $normalisedSearch = null,
        string $sort = self::SORT_TOWN,
        int|string|null $cursorKey = null,
        ?string $cursorId = null,
    ): array {
        $qb = $this->createQueryBuilder('l')
            ->select(
                'l.id',
                'l.slug',
                'l.town',
                'l.localisedNames',
                'l.region',
                'l.lat',
                'l.lng',
                'l.population',
                'l.populationSource',
                'l.populationAsOf',
                'l.landPence',
                'l.landValuedAt',
                'l.landSource',
                'l.isCapital',
                'l.citySize',
                'l.status',
                'l.enrichmentStatus',
            )
            ->where('l.country = :country')
            ->andWhere('l.status = :status')
            ->setParameter('country', strtoupper($countryCode))
            ->setParameter('status', $status)
            ->setMaxResults($limit + 1);

        if ($regions !== null && $regions !== []) {
            $qb->andWhere('l.region IN (:regions)')->setParameter('regions', $regions);
        }

        if ($maxLandPence !== null) {
            // EXCLUDES UNPRICED ROWS, deliberately. A caller asking for "under £2m" cannot be
            // served a town whose value is not known yet — it might be over. The un-enriched
            // rows are still reachable without this filter.
            $qb->andWhere('l.landPence IS NOT NULL')
                ->andWhere('l.landPence <= :maxLand')
                ->setParameter('maxLand', $maxLandPence);
        }

        if ($normalisedSearch !== null && $normalisedSearch !== '') {
            // Matched against town_normalised, not town, so a search for "munchen" finds
            // München — the same normalisation ClubNameNormalizer applies to the stored column.
            $qb->andWhere('l.townNormalised LIKE :q')
                ->setParameter('q', '%' . $normalisedSearch . '%');
        }

        if ($sort === self::SORT_PRICE) {
            $qb->addSelect('COALESCE(l.landPence, :sentinel) AS sortKey')
                ->setParameter('sentinel', self::UNPRICED_SORT_KEY)
                ->orderBy('sortKey', 'ASC')
                ->addOrderBy('l.id', 'ASC');

            if ($cursorKey !== null && $cursorId !== null) {
                $qb->andWhere(
                    '(COALESCE(l.landPence, :sentinel) > :cursorKey'
                    . ' OR (COALESCE(l.landPence, :sentinel) = :cursorKey AND l.id > :cursorId))',
                )
                    ->setParameter('cursorKey', (int) $cursorKey)
                    ->setParameter('cursorId', $cursorId);
            }
        } else {
            $qb->orderBy('l.town', 'ASC')->addOrderBy('l.id', 'ASC');

            if ($cursorKey !== null && $cursorId !== null) {
                $qb->andWhere('(l.town > :cursorKey OR (l.town = :cursorKey AND l.id > :cursorId))')
                    ->setParameter('cursorKey', (string) $cursorKey)
                    ->setParameter('cursorId', $cursorId);
            }
        }

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Region list with counts, plus the price range — backs GET /api/locations/facets.
     *
     * One GROUP BY per country off idx_location_country_region. Counted over ACTIVE rows only,
     * so a facet never offers a filter that returns nothing.
     *
     * @return array{regions: list<array{region: string, count: int}>,
     *     landPenceMin: ?int, landPenceMax: ?int, pricedCount: int, totalCount: int}
     */
    public function facetsFor(string $countryCode): array
    {
        $code = strtoupper($countryCode);

        $regions = $this->createQueryBuilder('l')
            ->select('l.region AS region', 'COUNT(l.id) AS cnt')
            ->where('l.country = :country')
            ->andWhere('l.status = :active')
            ->setParameter('country', $code)
            ->setParameter('active', LocationStatus::ACTIVE)
            ->groupBy('l.region')
            ->orderBy('l.region', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $range = $this->createQueryBuilder('l')
            ->select(
                'MIN(l.landPence) AS minLand',
                'MAX(l.landPence) AS maxLand',
                'COUNT(l.landPence) AS priced',
                'COUNT(l.id) AS total',
            )
            ->where('l.country = :country')
            ->andWhere('l.status = :active')
            ->setParameter('country', $code)
            ->setParameter('active', LocationStatus::ACTIVE)
            ->getQuery()
            ->getSingleResult();

        return [
            'regions' => array_map(
                static fn (array $r): array => ['region' => (string) $r['region'], 'count' => (int) $r['cnt']],
                $regions,
            ),
            // NULL until at least one location in the country has been enriched — an empty
            // price range is a real pre-enrichment state, not a missing value.
            'landPenceMin' => $range['minLand'] !== null ? (int) $range['minLand'] : null,
            'landPenceMax' => $range['maxLand'] !== null ? (int) $range['maxLand'] : null,
            'pricedCount'  => (int) $range['priced'],
            'totalCount'   => (int) $range['total'],
        ];
    }

    /** @return list<Location> */
    public function findWithStaleRivals(?string $countryCode, int $limit): array
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.status = :active')
            ->andWhere('l.lat IS NOT NULL')
            ->andWhere('l.lng IS NOT NULL')
            ->andWhere('l.rivalsComputedAt IS NULL')
            ->setParameter('active', LocationStatus::ACTIVE)
            ->orderBy('l.population', 'DESC')
            ->setMaxResults($limit);

        if ($countryCode !== null) {
            $qb->andWhere('l.country = :country')->setParameter('country', strtoupper($countryCode));
        }

        return $qb->getQuery()->getResult();
    }
}
