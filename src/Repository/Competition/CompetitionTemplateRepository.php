<?php

namespace App\Repository\Competition;

use App\Entity\Competition\CompetitionTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitionTemplate>
 */
class CompetitionTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitionTemplate::class);
    }

    /** @return list<CompetitionTemplate> */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.isActive = true')
            ->getQuery()
            ->getResult();
    }
}
