<?php

namespace App\Repository;

use App\Entity\Academy;
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
    public function findByAcademy(Academy $academy): array
    {
        return $this->findBy(['academy' => $academy], ['season' => 'ASC']);
    }

    public function findByAcademyAndSeason(Academy $academy, int $season): ?SeasonSnapshot
    {
        return $this->findOneBy(['academy' => $academy, 'season' => $season]);
    }
}
