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
