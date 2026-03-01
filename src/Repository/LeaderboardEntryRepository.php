<?php

namespace App\Repository;

use App\Entity\Academy;
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

    /** @return LeaderboardEntry[] */
    public function findTopByPeriod(LeaderboardCategory $category, string $period, int $limit = 50): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.academy', 'a')
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
     * Returns ['entry' => LeaderboardEntry, 'rank' => int] or null if the academy
     * has no entry for this category/period.
     */
    public function findWithRankForAcademy(Academy $academy, LeaderboardCategory $category, string $period): ?array
    {
        $entry = $this->findOneBy([
            'academy'  => $academy,
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

    public function findOrCreate(Academy $academy, LeaderboardCategory $category, string $period): LeaderboardEntry
    {
        $entry = $this->findOneBy([
            'academy'  => $academy,
            'category' => $category,
            'period'   => $period,
        ]);

        if ($entry === null) {
            $entry = new LeaderboardEntry($academy, $category, $period);
            $this->getEntityManager()->persist($entry);
        }

        return $entry;
    }
}
