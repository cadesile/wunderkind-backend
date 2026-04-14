<?php

namespace App\Repository;

use App\Entity\Academy;
use App\Entity\SeasonRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeasonRecord>
 */
class SeasonRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeasonRecord::class);
    }

    /** @return SeasonRecord[] ordered by season ASC */
    public function findByAcademy(Academy $academy): array
    {
        return $this->findBy(['academy' => $academy], ['season' => 'ASC']);
    }
}
