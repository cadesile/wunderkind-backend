<?php

declare(strict_types=1);

namespace App\Repository\Location;

use App\Entity\Location\Location;
use App\Entity\Location\StadiumEstimate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StadiumEstimate>
 */
class StadiumEstimateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StadiumEstimate::class);
    }

    /** The live estimate for a location under one model version, if there is one. */
    public function findCurrentFor(Location $location, string $modelVersion): ?StadiumEstimate
    {
        return $this->createQueryBuilder('e')
            ->where('e.location = :location')
            ->andWhere('e.modelVersion = :version')
            ->andWhere('e.supersededAt IS NULL')
            ->setParameter('location', $location->getId())
            ->setParameter('version', $modelVersion)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Current estimates for a page of locations, in one query.
     *
     * @param list<string> $locationIds
     *
     * @return array<string, StadiumEstimate> keyed by location id
     */
    public function findCurrentByLocations(array $locationIds, string $modelVersion): array
    {
        if ($locationIds === []) {
            return [];
        }

        $estimates = $this->createQueryBuilder('e')
            ->where('e.location IN (:ids)')
            ->andWhere('e.modelVersion = :version')
            ->andWhere('e.supersededAt IS NULL')
            ->setParameter('ids', $locationIds)
            ->setParameter('version', $modelVersion)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($estimates as $estimate) {
            $out[(string) $estimate->getLocation()->getId()] = $estimate;
        }

        return $out;
    }

    /**
     * Supersede every live estimate for a location, whatever its model version.
     *
     * A bulk UPDATE rather than loading and mutating: this runs in a loop over every location
     * during a refresh pass, and the rows are not otherwise needed in memory. Never a DELETE —
     * plot_hold.stadium_estimate_id is ON DELETE RESTRICT precisely so a live hold's quote
     * cannot be destroyed underneath it.
     */
    public function supersedeAllFor(Location $location, \DateTimeImmutable $at): int
    {
        return (int) $this->createQueryBuilder('e')
            ->update()
            ->set('e.supersededAt', ':at')
            ->where('e.location = :location')
            ->andWhere('e.supersededAt IS NULL')
            ->setParameter('at', $at)
            ->setParameter('location', $location->getId())
            ->getQuery()
            ->execute();
    }

    /**
     * Locations whose current estimate is missing, stale, or built from an older model.
     *
     * The query that drives app:plots:refresh-estimates. Deliberately a projection of ids: the
     * caller re-reads each location it is about to work on, and hydrating every candidate up
     * front would decode a jsonb column per row for nothing.
     *
     * @return list<string> location ids
     */
    public function findLocationIdsNeedingEstimate(
        string $modelVersion,
        \DateTimeImmutable $now,
        ?string $countryCode,
        int $limit,
    ): array {
        $sql = "SELECT l.id
                  FROM location l
                  LEFT JOIN stadium_estimate e
                         ON e.location_id = l.id
                        AND e.superseded_at IS NULL
                        AND e.model_version = :version
                 WHERE l.status = 'active'
                   AND l.population IS NOT NULL
                   AND l.land_pence IS NOT NULL
                   AND (
                         e.id IS NULL
                      OR (e.valid_until IS NOT NULL AND e.valid_until <= :now)
                      -- Land was revalued after the estimate was built, so the figure is
                      -- derived from a price that no longer applies.
                      OR (l.land_valued_at IS NOT NULL AND l.land_valued_at > e.created_at)
                   )";

        $params = ['version' => $modelVersion, 'now' => $now];
        $types  = ['now' => 'datetime_immutable'];

        if ($countryCode !== null) {
            $sql .= ' AND l.country_code = :country';
            $params['country'] = strtoupper($countryCode);
        }

        $sql .= ' ORDER BY l.population DESC LIMIT ' . max(1, $limit);

        return array_map(
            static fn (array $row): string => (string) $row['id'],
            $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params, $types),
        );
    }
}
