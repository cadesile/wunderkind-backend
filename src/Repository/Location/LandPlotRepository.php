<?php

declare(strict_types=1);

namespace App\Repository\Location;

use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Enum\Location\LandPlotStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LandPlot>
 */
class LandPlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LandPlot::class);
    }

    /** @return list<LandPlot> */
    public function findActiveByLocation(Location $location): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.location = :location')
            ->andWhere('p.status = :active')
            ->setParameter('location', $location->getId())
            ->setParameter('active', LandPlotStatus::ACTIVE)
            ->orderBy('p.plotNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every active plot for a page of locations, in one query.
     *
     * The batch shape the house style calls for (CompetitionEntrantSquadRepository's
     * findByEntrants() and friends) so the list endpoint never pays an N+1 for plots.
     *
     * @param list<string> $locationIds
     *
     * @return array<string, list<LandPlot>> keyed by location id
     */
    public function findActiveByLocationIds(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        $plots = $this->createQueryBuilder('p')
            ->where('p.location IN (:ids)')
            ->andWhere('p.status = :active')
            ->setParameter('ids', $locationIds)
            ->setParameter('active', LandPlotStatus::ACTIVE)
            ->orderBy('p.plotNumber', 'ASC')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($plots as $plot) {
            $out[(string) $plot->getLocation()->getId()][] = $plot;
        }

        return $out;
    }

    public function countByLocation(Location $location): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.location = :location')
            ->setParameter('location', $location->getId())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Next free plot_number for a location, so uq_land_plot_location_number cannot collide. */
    public function nextPlotNumber(Location $location): int
    {
        $max = $this->createQueryBuilder('p')
            ->select('MAX(p.plotNumber)')
            ->where('p.location = :location')
            ->setParameter('location', $location->getId())
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 1 : ((int) $max) + 1;
    }
}
