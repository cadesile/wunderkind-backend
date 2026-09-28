<?php

namespace App\Repository\Competition;

use App\Entity\Competition\EntrantRewardClaim;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EntrantRewardClaim>
 */
class EntrantRewardClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntrantRewardClaim::class);
    }
}
