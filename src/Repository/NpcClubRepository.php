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

    /** @return string[] distinct, non-null region values currently in use, alphabetically sorted */
    public function findDistinctRegions(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('DISTINCT c.region')
            ->where('c.region IS NOT NULL')
            ->orderBy('c.region', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'region');
    }

    /**
     * Find NPC clubs NOT from the given country, optionally filtered by tier
     * derived from a rep string.
     *
     * @param string      $excludeCountry Country code to exclude (e.g. 'EN')
     * @param string|null $rep            'local'|'regional'|'national'|'elite'|null
     * @param int         $limitPerTier   Max clubs per tier
     * @return array<int, array{id: string, name: string, country: string, tier: int, region: ?string, citySize: string, populationSize: int, isCapital: bool}>
     */
    public function findForeignClubs(string $excludeCountry, ?string $rep = null, int $limitPerTier = 3): array
    {
        $tierMap = [
            'elite'    => [1],
            'national' => [2],
            'regional' => [3],
            'local'    => [4, 5],
        ];
        $tiers = ($rep !== null) ? ($tierMap[$rep] ?? null) : null;

        $columns = 'id, name, country, tier, region, city_size, population_size, is_capital';
        if ($tiers !== null) {
            $placeholders = implode(',', array_fill(0, count($tiers), '?'));
            $params       = array_merge([$excludeCountry], $tiers);
            $sql          = "SELECT {$columns} FROM npc_club WHERE country != ? AND tier IN ({$placeholders}) ORDER BY tier ASC, RANDOM()";
        } else {
            $params = [$excludeCountry];
            $sql    = "SELECT {$columns} FROM npc_club WHERE country != ? ORDER BY tier ASC, RANDOM()";
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery($sql, $params)->fetchAllAssociative();

        $byTier = [];
        foreach ($rows as $row) {
            $tier = (int) $row['tier'];
            if (!isset($byTier[$tier]) || count($byTier[$tier]) < $limitPerTier) {
                $byTier[$tier][] = [
                    'id'             => $row['id'],
                    'name'           => $row['name'],
                    'country'        => $row['country'],
                    'tier'           => $tier,
                    'region'         => $row['region'],
                    'citySize'       => $row['city_size'],
                    'populationSize' => (int) $row['population_size'],
                    'isCapital'      => (bool) $row['is_capital'],
                ];
            }
        }

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
