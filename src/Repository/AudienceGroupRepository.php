<?php

namespace App\Repository;

use App\Entity\AudienceGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AudienceGroup>
 */
class AudienceGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AudienceGroup::class);
    }

    public function findOneBySlug(string $slug): ?AudienceGroup
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
