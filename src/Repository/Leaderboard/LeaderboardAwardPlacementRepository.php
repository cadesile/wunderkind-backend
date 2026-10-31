<?php

declare(strict_types=1);

namespace App\Repository\Leaderboard;

use App\Entity\Leaderboard\LeaderboardAwardPlacement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeaderboardAwardPlacement>
 */
class LeaderboardAwardPlacementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaderboardAwardPlacement::class);
    }
}
