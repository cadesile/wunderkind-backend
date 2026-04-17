<?php

namespace App\Repository;

use App\Entity\Club;
use App\Entity\SeasonSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeasonSnapshot>
 */
class SeasonSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeasonSnapshot::class);
    }

    /** @return SeasonSnapshot[] ordered by season ASC */
    public function findByClub(Club $club): array
    {
        return $this->findBy(['club' => $club], ['season' => 'ASC']);
    }

    public function findByClubAndSeason(Club $club, int $season): ?SeasonSnapshot
    {
        return $this->findOneBy(['club' => $club, 'season' => $season]);
    }
}
