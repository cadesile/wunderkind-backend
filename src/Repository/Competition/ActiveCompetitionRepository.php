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

    /** Locks the row for update — used to serialize concurrent "last slot" registrations. */
    public function findForUpdate(string $id): ?ActiveCompetition
    {
        return $this->createQueryBuilder('a')
            ->where('a.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }
}
