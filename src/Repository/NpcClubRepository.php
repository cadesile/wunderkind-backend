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

    /**
     * Returns NPC clubs whose country does NOT match the given country,
     * up to $limitPerTier clubs per tier, in random order.
     *
     * @param string $excludeCountry Country code to exclude (e.g. 'EN')
     * @param int    $limitPerTier   Max clubs to return per tier
     * @return array<int, array{id: string, name: string, country: string, tier: int}>
     */
    public function findForeignClubs(string $excludeCountry, int $limitPerTier = 3): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT id, name, country, tier FROM npc_club WHERE country != :country ORDER BY tier ASC, RANDOM()',
            ['country' => $excludeCountry],
        )->fetchAllAssociative();

        // Group by tier and cap each tier at $limitPerTier entries.
        $byTier = [];
        foreach ($rows as $row) {
            $tier = (int) $row['tier'];
            if (!isset($byTier[$tier]) || count($byTier[$tier]) < $limitPerTier) {
                $byTier[$tier][] = [
                    'id'      => $row['id'],
                    'name'    => $row['name'],
                    'country' => $row['country'],
                    'tier'    => $tier,
                ];
            }
        }

        // Flatten back to a single array sorted by tier.
        ksort($byTier);
        return array_merge(...array_values($byTier)) ?: [];
    }

    /**
     * Returns all clubs that have a league assigned, grouped by league UUID string.
     *
     * @return array<string, NpcClub[]>
     */
    public function getAllGroupedByLeague(): array
    {
        $clubs = $this->createQueryBuilder('c')
            ->innerJoin('c.league', 'l')
            ->addSelect('l')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($clubs as $club) {
            $grouped[(string) $club->getLeague()->getId()][] = $club;
        }
        return $grouped;
    }
}
