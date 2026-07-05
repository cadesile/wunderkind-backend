<?php

namespace App\Repository;

use App\Entity\Club;
use App\Entity\SeasonRecord;
use App\Enum\StatsPeriod;
use App\Service\PeriodResolver;
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
    public function findByClub(Club $club): array
    {
        return $this->findBy(['club' => $club], ['season' => 'ASC']);
    }

    /** @return array<array{clubId: string, clubName: string, value: int|string}> */
    public function getMostSeasonsByClub(StatsPeriod $period, int $limit, PeriodResolver $resolver): array
    {
        $qb = $this->createQueryBuilder('sr')
            ->select('c.id as clubId, c.name as clubName, COUNT(sr.id) as value')
            ->innerJoin('sr.club', 'c')
            ->groupBy('c.id')
            ->orderBy('value', 'DESC')
            ->setMaxResults($limit);

        $resolver->applyPeriodFilter($qb, $period, 'sr', 'createdAt', 'c');

        return $qb->getQuery()->getArrayResult();
    }

    /** @return array<array{clubId: string, clubName: string, value: int|string}> */
    public function getMostTrophiesByClub(StatsPeriod $period, int $limit, PeriodResolver $resolver): array
    {
        $qb = $this->createQueryBuilder('sr')
            ->select('c.id as clubId, c.name as clubName, COUNT(sr.id) as value')
            ->innerJoin('sr.club', 'c')
            ->where('sr.finalPosition = :finalPosition')
            ->setParameter('finalPosition', 1)
            ->groupBy('c.id')
            ->orderBy('value', 'DESC')
            ->setMaxResults($limit);

        $resolver->applyPeriodFilter($qb, $period, 'sr', 'createdAt', 'c');

        return $qb->getQuery()->getArrayResult();
    }
}
