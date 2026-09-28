<?php

namespace App\Repository\WorldPack;

use App\Entity\WorldPack\WorldPackGenerationClubRun;
use App\Entity\WorldPack\WorldPackGenerationTierRun;
use App\Enum\WorldPack\WorldPackGenerationClubRunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorldPackGenerationClubRun>
 */
class WorldPackGenerationClubRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorldPackGenerationClubRun::class);
    }

    /** @return list<WorldPackGenerationClubRun> */
    public function findByTierRun(WorldPackGenerationTierRun $tierRun): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.tierRun = :tierRun')
            ->setParameter('tierRun', $tierRun->getId())
            ->orderBy('c.clubName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<WorldPackGenerationClubRun> */
    public function findByTierRunAndStatus(WorldPackGenerationTierRun $tierRun, WorldPackGenerationClubRunStatus $status): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.tierRun = :tierRun')
            ->andWhere('c.status = :status')
            ->setParameter('tierRun', $tierRun->getId())
            ->setParameter('status', $status)
            ->getQuery()
            ->getResult();
    }

    /** @return array<string, int> counts keyed by WorldPackGenerationClubRunStatus value */
    public function countByTierRunGroupedByStatus(WorldPackGenerationTierRun $tierRun): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.status AS status', 'COUNT(c.id) AS cnt')
            ->where('c.tierRun = :tierRun')
            ->setParameter('tierRun', $tierRun->getId())
            ->groupBy('c.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $status           = $row['status'] instanceof WorldPackGenerationClubRunStatus ? $row['status']->value : $row['status'];
            $counts[$status]  = (int) $row['cnt'];
        }

        return $counts;
    }
}
