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
     * Returns the list of tier numbers already cached for a country at the given
     * payload version. A row stamped with an older version is deliberately not
     * counted — getOrBuild would rebuild it anyway, so reporting it as cached
     * would let a caller skip warming and then pay the rebuild on a user request.
     *
     * @return int[]
     */
    public function findCachedTiers(string $country, ?int $payloadVersion = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.tier')
            ->where('c.country = :country')
            ->setParameter('country', $country);

        if ($payloadVersion !== null) {
            $qb->andWhere('c.payloadVersion = :version')
               ->setParameter('version', $payloadVersion);
        }

        return array_column($qb->getQuery()->getArrayResult(), 'tier');
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

    /**
     * Returns all cache entries ordered by country (ASC) then tier (ASC).
     * NOTE: hydrates the full payload column — use findAllSummaries() for the admin list view.
     *
     * @return CountryWorldPackCache[]
     */
    public function findAllOrderedByCountryAndTier(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.country', 'ASC')
            ->addOrderBy('c.tier', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lightweight summary rows for the admin cache overview.
     * Club and player counts are computed in PostgreSQL using JSON functions so
     * the payload column is never loaded into PHP memory.
     *
     * @return array<array{id:string, country:string, tier:int, club_count:int, player_count:int, generated_at:string}>
     */
    public function findAllSummaries(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT
                id::text                                        AS id,
                country,
                tier,
                generated_at,
                COALESCE(json_array_length(payload->'clubs'), 0)         AS club_count,
                COALESCE((
                    SELECT SUM(json_array_length(c->'players'))
                    FROM   json_array_elements(payload->'clubs') AS c
                ), 0)                                           AS player_count
             FROM  country_world_pack_cache
             ORDER BY country ASC, tier ASC"
        );

        return array_map(static function (array $row): array {
            return [
                'id'          => $row['id'],
                'country'     => $row['country'],
                'tier'        => (int) $row['tier'],
                'clubCount'   => (int) $row['club_count'],
                'playerCount' => (int) $row['player_count'],
                'generatedAt' => new \DateTimeImmutable($row['generated_at']),
            ];
        }, $rows);
    }
}
