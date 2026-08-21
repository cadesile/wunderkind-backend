<?php

namespace App\Repository;

use App\Entity\AudienceGroup;
use App\Entity\AudienceGroupMember;
use App\Entity\Club;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AudienceGroupMember>
 */
class AudienceGroupMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AudienceGroupMember::class);
    }

    public function isMember(Club $club, AudienceGroup $group): bool
    {
        return $this->count(['club' => $club, 'group' => $group]) > 0;
    }
}
