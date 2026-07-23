<?php

namespace App\Repository;

use App\Entity\Club;
use App\Entity\LeaderboardEntry;
use App\Enum\LeaderboardCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class LeaderboardEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaderboardEntry::class);
    }

    /** All entries for a category/period, ordered by score descending (full result — used for ranking passes and cache hydration). */
    public function findAllOrderedByScore(LeaderboardCategory $category, string $period): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.club', 'c')
            ->addSelect('c')
            ->where('e.category = :category')
            ->andWhere('e.period = :period')
            ->setParameter('category', $category)
            ->setParameter('period', $period)
            ->orderBy('e.score', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return LeaderboardEntry[] */
    public function findTopByPeriod(LeaderboardCategory $category, string $period, int $limit = 50): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.club', 'a')
            ->addSelect('a')
            ->where('e.category = :category')
            ->andWhere('e.period = :period')
            ->setParameter('category', $category)
            ->setParameter('period', $period)
            ->orderBy('e.score', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns ['entry' => LeaderboardEntry, 'rank' => int] or null if the club
     * has no entry for this category/period.
     */
    public function findWithRankForClub(Club $club, LeaderboardCategory $category, string $period): ?array
    {
        $entry = $this->findOneBy([
            'club'  => $club,
            'category' => $category,
            'period'   => $period,
        ]);

        if ($entry === null) {
            return null;
        }

        $higherCount = (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.category = :category')
            ->andWhere('e.period = :period')
            ->andWhere('e.score > :myScore')
            ->setParameter('category', $category)
            ->setParameter('period', $period)
            ->setParameter('myScore', $entry->getScore())
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'entry' => $entry,
            'rank'  => $higherCount + 1,
        ];
    }

    public function findOrCreate(Club $club, LeaderboardCategory $category, string $period): LeaderboardEntry
    {
        $entry = $this->findOneBy([
            'club'  => $club,
            'category' => $category,
            'period'   => $period,
        ]);

        if ($entry === null) {
            $entry = new LeaderboardEntry($club, $category, $period);
            $this->getEntityManager()->persist($entry);
        }

        return $entry;
    }
}
