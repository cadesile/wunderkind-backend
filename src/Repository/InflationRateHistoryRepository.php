<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InflationRateHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InflationRateHistory>
 */
class InflationRateHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InflationRateHistory::class);
    }

    /**
     * Most recent changes first — the order the admin screen reads them in.
     *
     * @return list<InflationRateHistory>
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('h')
            ->orderBy('h.recordedAt', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * The rate as last recorded, or null if nothing ever has been.
     *
     * NOT THE AUTHORITY ON THE CURRENT RATE — `game_config.inflation_rate_per_season_bp` is.
     * This exists so a write can record what it is replacing without the caller having to
     * remember, and so an empty history (the shipped state) is distinguishable from a recorded
     * rate of zero.
     */
    public function latestRateBp(): ?int
    {
        $row = $this->createQueryBuilder('h')
            ->select('h.ratePerSeasonBp')
            ->orderBy('h.recordedAt', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row === null ? null : (int) $row['ratePerSeasonBp'];
    }
}
