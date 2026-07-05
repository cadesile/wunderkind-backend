<?php

namespace App\Repository;

use App\Entity\SocialAccountConnection;
use App\Enum\SocialPlatform;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialAccountConnection>
 */
class SocialAccountConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialAccountConnection::class);
    }

    public function findByPlatformAndExternalId(SocialPlatform $platform, string $externalAccountId): ?SocialAccountConnection
    {
        return $this->findOneBy([
            'platform'          => $platform,
            'externalAccountId' => $externalAccountId,
        ]);
    }

    /** @return SocialAccountConnection[] ordered by platform then connectedAt descending */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.platform', 'ASC')
            ->addOrderBy('c.connectedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
