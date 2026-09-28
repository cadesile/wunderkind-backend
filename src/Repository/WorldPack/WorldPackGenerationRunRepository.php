<?php

namespace App\Repository\WorldPack;

use App\Entity\WorldPack\WorldPackGenerationRun;
use App\Enum\WorldPack\WorldPackGenerationRunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorldPackGenerationRun>
 */
class WorldPackGenerationRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorldPackGenerationRun::class);
    }

    /** The currently active (pending/in_progress) run for a country, if any — mirrors the DB's own single-flight partial unique index. */
    public function findActiveForCountry(string $country): ?WorldPackGenerationRun
    {
        return $this->createQueryBuilder('r')
            ->where('r.country = :country')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('country', $country)
            ->setParameter('statuses', [
                WorldPackGenerationRunStatus::PENDING,
                WorldPackGenerationRunStatus::IN_PROGRESS,
            ])
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Most recent run for a country, active or finished — what the admin UI polls when no explicit run id is given. */
    public function findLatestForCountry(string $country): ?WorldPackGenerationRun
    {
        return $this->createQueryBuilder('r')
            ->where('r.country = :country')
            ->setParameter('country', $country)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
