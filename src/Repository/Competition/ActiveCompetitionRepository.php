<?php

namespace App\Repository\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionTemplate;
use App\Enum\Competition\ActiveCompetitionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActiveCompetition>
 */
class ActiveCompetitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActiveCompetition::class);
    }

    public function findOpenForTemplate(CompetitionTemplate $template): ?ActiveCompetition
    {
        return $this->createQueryBuilder('a')
            ->where('a.template = :template')
            ->andWhere('a.status = :status')
            ->setParameter('template', $template->getId())
            ->setParameter('status', ActiveCompetitionStatus::REGISTERING)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<ActiveCompetition> */
    public function findByStatus(ActiveCompetitionStatus $status): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->setParameter('status', $status)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<ActiveCompetition> Most recently completed instances, for "historical winners." */
    public function findRecentlyCompleted(int $limit): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->setParameter('status', ActiveCompetitionStatus::COMPLETED)
            ->orderBy('a.completedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
