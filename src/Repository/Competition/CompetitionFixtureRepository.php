<?php

namespace App\Repository\Competition;

use App\Entity\Competition\CompetitionFixture;
use App\Entity\Competition\CompetitionRound;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitionFixture>
 */
class CompetitionFixtureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitionFixture::class);
    }

    /** @return list<CompetitionFixture> */
    public function findByRoundOrderedBySlot(CompetitionRound $round): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.round = :round')
            ->setParameter('round', $round->getId())
            ->orderBy('f.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
