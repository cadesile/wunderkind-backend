<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CountryWorldPackCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CountryWorldPackCache>
 */
class CountryWorldPackCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CountryWorldPackCache::class);
    }

    public function findForCountryAndTier(string $country, int $tier): ?CountryWorldPackCache
    {
        return $this->findOneBy(['country' => $country, 'tier' => $tier]);
    }

    /**
     * Returns the list of tier numbers already cached for a country.
     *
     * @return int[]
     */
    public function findCachedTiers(string $country): array
    {
        return array_column(
            $this->createQueryBuilder('c')
                ->select('c.tier')
                ->where('c.country = :country')
                ->setParameter('country', $country)
                ->getQuery()
                ->getArrayResult(),
            'tier'
        );
    }

    public function deleteByCountry(string $country): int
    {
        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->where('c.country = :country')
            ->setParameter('country', $country)
            ->getQuery()
            ->execute();
    }
}
