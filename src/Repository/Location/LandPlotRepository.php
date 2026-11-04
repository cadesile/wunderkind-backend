<?php

declare(strict_types=1);

namespace App\Repository\Location;

use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Enum\Location\LandPlotStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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

    /**
     * Plots plus their claim state for a page of locations, in ONE query.
     *
     * Joins plot_claim and club even though this is the plot repository, and that is the point:
     * the list endpoint needs "which plots does this town have, and who owns them" and fetching
     * those separately is two round trips for data the database can return together. Raw DBAL
     * and a column projection, because the endpoint wants five scalars and ORM 3 would decode
     * every column of a LandPlot, a PlotClaim and a Club to produce them.
     *
     * The claim join carries `released_at IS NULL` in the ON clause rather than the WHERE, so a
     * plot with no active claim still comes back — moving it to WHERE would silently drop every
     * unclaimed plot, which is most of them.
     *
     * claimed_by prefers the frozen club_name_at_claim over the live club name, so a claim still
     * renders an owner after the club has been deleted. Same precedence as findClaimedByNames().
     *
     * @param list<string> $locationIds
     *
     * IS_CLAIMED IS SEPARATE FROM CLAIMED_BY, and that is not redundancy. Land is buyable
     * before a club exists, so a legitimate active claim can have no club and therefore no
     * display name at all — claimed_by is NULL for it. A list that inferred "free" from a NULL
     * claimed_by would offer a sold plot for sale, and the buyer would only find out at the
     * till. is_claimed is the fact; claimed_by is just the label.
     *
     * @return array<string, list<array{plotId: string, plotNumber: int,
     *     landPenceOverride: ?int, isClaimed: bool, claimedBy: ?string, claimedByUserId: ?string}>>
     *     keyed by location id, plots in plot_number order
     */
    public function findActivePlotsWithClaimsByLocationIds(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT p.id            AS plot_id,
                    p.location_id   AS location_id,
                    p.plot_number   AS plot_number,
                    p.land_pence_override AS land_pence_override,
                    pc.id           AS claim_id,
                    pc.user_id      AS claim_user_id,
                    COALESCE(pc.club_name_at_claim, c.name) AS claimed_by
               FROM land_plot p
               LEFT JOIN plot_claim pc ON pc.plot_id = p.id AND pc.released_at IS NULL
               LEFT JOIN club c        ON c.id = pc.club_id
              WHERE p.location_id IN (:ids)
                AND p.status = :active
              ORDER BY p.location_id, p.plot_number',
            ['ids' => $locationIds, 'active' => LandPlotStatus::ACTIVE->value],
            ['ids' => ArrayParameterType::STRING],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['location_id']][] = [
                'plotId'            => (string) $row['plot_id'],
                'plotNumber'        => (int) $row['plot_number'],
                'landPenceOverride' => $row['land_pence_override'] !== null ? (int) $row['land_pence_override'] : null,
                'isClaimed'         => $row['claim_id'] !== null,
                'claimedBy'         => $row['claimed_by'] !== null ? (string) $row['claimed_by'] : null,
                'claimedByUserId'   => $row['claim_user_id'] !== null ? (string) $row['claim_user_id'] : null,
            ];
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
