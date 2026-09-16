<?php

namespace App\Repository\Competition;

use App\Entity\Competition\CompetitionRound;
use App\Enum\Competition\CompetitionRoundStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitionRound>
 */
class CompetitionRoundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitionRound::class);
    }

    /**
     * Rounds due for execution — the query the round processor cron runs every tick.
     *
     * @return list<CompetitionRound>
     */
    public function findDueRounds(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status IN (:statuses)')
            ->andWhere('r.scheduledAt <= :now')
            ->setParameter('statuses', [CompetitionRoundStatus::PENDING, CompetitionRoundStatus::SCHEDULED])
            ->setParameter('now', $now)
            ->orderBy('r.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
