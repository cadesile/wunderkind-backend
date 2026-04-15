<?php

namespace App\Repository;

use App\Entity\League;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<League>
 */
class LeagueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, League::class);
    }

    public function findByCountryAndTier(string $country, int $tier): ?League
    {
        return $this->findOneBy(['country' => $country, 'tier' => $tier]);
    }

    /** @return League[] sorted tier ASC */
    public function findByCountry(string $country): array
    {
        return $this->findBy(['country' => $country], ['tier' => 'ASC']);
    }

    /**
     * Returns the league with the highest tier number (lowest division) for the given country.
     * Highest `tier` integer = lowest division (tier 8 is below tier 1).
     */
    public function findLowestTierForCountry(string $country): ?League
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.country = :country')
            ->setParameter('country', $country)
            ->orderBy('l.tier', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
