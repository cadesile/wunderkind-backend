<?php

namespace App\Repository;

use App\Entity\SocialPostTemplate;
use App\Enum\SocialPlatform;
use App\Enum\StatCategory;
use App\Enum\StatsPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialPostTemplate>
 */
class SocialPostTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialPostTemplate::class);
    }

    public function findByCategoryAndPlatform(StatCategory $category, SocialPlatform $platform, StatsPeriod $period): ?SocialPostTemplate
    {
        return $this->findOneBy(['category' => $category, 'platform' => $platform, 'period' => $period]);
    }

    /** Same lookup, but only returns a result if the template is active — used by the cron command. */
    public function findActiveByCategoryAndPlatform(StatCategory $category, SocialPlatform $platform, StatsPeriod $period): ?SocialPostTemplate
    {
        return $this->findOneBy(['category' => $category, 'platform' => $platform, 'period' => $period, 'isActive' => true]);
    }

    /** @return SocialPostTemplate[] ordered by category then platform, for the admin dropdown */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.category', 'ASC')
            ->addOrderBy('t.platform', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
