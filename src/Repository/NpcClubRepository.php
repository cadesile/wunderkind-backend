<?php

namespace App\Repository;

use App\Entity\League;
use App\Entity\NpcClub;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NpcClub>
 */
class NpcClubRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NpcClub::class);
    }

    /**
     * Returns counts keyed as [country][tier] => count.
     * e.g. ['ES' => [1 => 8, 2 => 6], 'EN' => [3 => 10]]
     *
     * @return array<string, array<int, int>>
     */
    public function getCountsByCountryAndTier(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.country, c.tier, COUNT(c.id) AS cnt')
            ->groupBy('c.country, c.tier')
            ->orderBy('c.country', 'ASC')
            ->addOrderBy('c.tier', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['country']][(int) $row['tier']] = (int) $row['cnt'];
        }
        return $result;
    }

    public function deleteByCountryAndTier(string $country, int $tier): int
    {
        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->where('c.country = :country')
            ->andWhere('c.tier = :tier')
            ->setParameter('country', $country)
            ->setParameter('tier', $tier)
            ->getQuery()
            ->execute();
    }

    /** @return NpcClub[] */
    public function findByLeague(League $league): array
    {
        return $this->findBy(['league' => $league]);
    }
}
