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

    /**
     * Ordered by name because four separate client-facing lists are built by looping this
     * result — `champions[]` and `available[]` on CompetitionController, and `open[]` plus
     * `champions[]` on the club hub — and all four inherit whatever order it comes back in.
     * Unordered, that was whatever Postgres felt like, so the same four lists could reshuffle
     * between two identical requests.
     *
     * @return list<CompetitionTemplate>
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.isActive = true')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
