<?php

declare(strict_types=1);

namespace App\Repository\Location;

use App\Entity\Location\Location;
use App\Entity\Location\LocationRival;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LocationRival>
 */
class LocationRivalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LocationRival::class);
    }

    /**
     * @return list<LocationRival> nearest first
     */
    public function findForLocation(Location $location): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('rival')
            ->join('r.rivalLocation', 'rival')
            ->where('r.location = :location')
            ->setParameter('location', $location->getId())
            ->orderBy('r.rank', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Rivals for several locations in one query.
     *
     * The batch method exists so splitting rivals into their own table does not trade a
     * hydration cost for an N+1 of the same size — the rule schema.md's "The split rows"
     * section states for every split in this schema.
     *
     * @param list<string> $locationIds
     *
     * @return array<string, list<LocationRival>> keyed by location id, nearest first
     */
    public function findByLocations(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        $rivals = $this->createQueryBuilder('r')
            ->addSelect('rival')
            ->join('r.rivalLocation', 'rival')
            ->where('r.location IN (:ids)')
            ->setParameter('ids', $locationIds)
            ->orderBy('r.rank', 'ASC')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rivals as $rival) {
            $out[(string) $rival->getLocation()->getId()][] = $rival;
        }

        return $out;
    }

    /**
     * Which locations list this one as a rival.
     *
     * Rivalry is directional (see LocationRival's docblock), so this is genuinely a different
     * question from findForLocation() and the answers routinely differ. Served by
     * idx_location_rival_rival.
     *
     * @return list<LocationRival>
     */
    public function findListingLocation(Location $location): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('owner')
            ->join('r.location', 'owner')
            ->where('r.rivalLocation = :location')
            ->setParameter('location', $location->getId())
            ->orderBy('r.distanceKm', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Drop every rival row for one location.
     *
     * A rival set is rewritten wholesale rather than diffed: the pass recomputes all four
     * ranks together, and UNIQUE(location, rank) makes an in-place reshuffle a constraint
     * fight for no benefit. DQL delete, so no entities are hydrated to be thrown away.
     */
    public function deleteForLocation(Location $location): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->where('r.location = :location')
            ->setParameter('location', $location->getId())
            ->getQuery()
            ->execute();
    }
}
