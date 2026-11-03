<?php

declare(strict_types=1);

namespace App\Repository\Location;

use App\Entity\Location\Location;
use App\Entity\Location\LocationEnrichmentAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LocationEnrichmentAttempt>
 *
 * `raw_response_json` makes every row here expensive to hydrate (ORM 3 decodes every mapped
 * column), so there is no "find all attempts" method and there should not be one. Fetch for a
 * single location, or in bulk for a known small set.
 */
class LocationEnrichmentAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LocationEnrichmentAttempt::class);
    }

    /** @return list<LocationEnrichmentAttempt> newest first */
    public function findForLocation(Location $location, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.location = :location')
            ->setParameter('location', $location->getId())
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findLatestForLocation(Location $location): ?LocationEnrichmentAttempt
    {
        return $this->findForLocation($location, 1)[0] ?? null;
    }

    /**
     * The batch method, so a detail page rendering several locations is one query not N.
     *
     * @param list<string> $locationIds
     *
     * @return array<string, list<LocationEnrichmentAttempt>> keyed by location id, newest first
     */
    public function findByLocations(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        $attempts = $this->createQueryBuilder('a')
            ->where('a.location IN (:ids)')
            ->setParameter('ids', $locationIds)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($attempts as $attempt) {
            $out[(string) $attempt->getLocation()->getId()][] = $attempt;
        }

        return $out;
    }

    /**
     * Token spend and call count over a window, for the admin overview.
     *
     * Projected, not hydrated — this must never pull raw_response_json.
     *
     * @return array{attempts: int, inputTokens: int, outputTokens: int}
     */
    public function usageSince(\DateTimeImmutable $since): array
    {
        $row = $this->createQueryBuilder('a')
            ->select(
                'COUNT(a.id) AS attempts',
                'COALESCE(SUM(a.inputTokens), 0) AS inputTokens',
                'COALESCE(SUM(a.outputTokens), 0) AS outputTokens',
            )
            ->where('a.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleResult();

        return [
            'attempts'     => (int) $row['attempts'],
            'inputTokens'  => (int) $row['inputTokens'],
            'outputTokens' => (int) $row['outputTokens'],
        ];
    }
}
