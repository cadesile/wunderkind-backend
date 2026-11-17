<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CountryWorldPackCache;
use App\Entity\WorldPack\WorldPackSet;
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

    /**
     * The cached payload row for one tier OF ONE SET.
     *
     * This replaced `findForCountryAndTier()`, and the old signature must not come back.
     * `(country, tier)` stopped being unique the moment a country began holding a buffer of
     * sets, so `findOneBy(['country','tier'])` would quietly return an arbitrary one of them —
     * a "which world did that client actually get?" bug with no symptom at the call site.
     * Reach a row through its set.
     */
    public function findForSetAndTier(WorldPackSet $set, int $tier): ?CountryWorldPackCache
    {
        return $this->findOneBy(['set' => $set, 'tier' => $tier]);
    }

    /**
     * Tier numbers present in $set, optionally restricted to one payload version.
     *
     * @return int[]
     */
    public function findTiersForSet(WorldPackSet $set, ?int $payloadVersion = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.tier')
            ->where('c.set = :set')
            ->setParameter('set', $set);

        if ($payloadVersion !== null) {
            $qb->andWhere('c.payloadVersion = :version')
               ->setParameter('version', $payloadVersion);
        }

        return array_column($qb->getQuery()->getArrayResult(), 'tier');
    }

    public function deleteBySet(WorldPackSet $set): int
    {
        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->where('c.set = :set')
            ->setParameter('set', $set)
            ->getQuery()
            ->execute();
    }

    /**
     * Drops the payload rows of every set claimed before $before, leaving the set rows
     * themselves as the audit trail of which club was served which generation.
     *
     * This is what stops the table growing by a whole country's worth of large JSON per new
     * game, forever. The payloads are dead the moment the device has them — the client writes
     * every tier into its own SQLite and `Club::$starterPackJson` holds the squad — but they
     * cannot be dropped at serve time, because a retried tier call replays from them and a
     * client's initialisation can span an app kill and resume.
     */
    public function deletePayloadsForSetsClaimedBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
            DELETE FROM country_world_pack_cache
             WHERE set_id IN (
                     SELECT id FROM world_pack_set
                      WHERE status = 'claimed'
                        AND claimed_at IS NOT NULL
                        AND claimed_at < :before
                   )
            SQL,
            ['before' => $before->format('Y-m-d H:i:s')],
        );
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
     * `payload_version` is selected so the admin can show staleness per row without a second
     * query: a row whose version differs from `WorldInitializationService::WORLD_PACK_VERSION`
     * is what the cache treats as a miss, so it is the same comparison the rebuild path makes.
     *
     * `set_id` is selected because this is raw DBAL with a hardcoded column list, which a new
     * mapped property is invisible to until it is named here — the trap
     * `.context/shared/api-backwards-compatibility.md` point 3 records against
     * NpcClubRepository::findForeignClubs(). The admin screen groups on it.
     *
     * @return array<array{id:string, setId:string, country:string, tier:int, clubCount:int, playerCount:int, payloadVersion:int, generatedAt:\DateTimeImmutable}>
     */
    public function findAllSummaries(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT
                id::text                                        AS id,
                set_id::text                                    AS set_id,
                country,
                tier,
                payload_version,
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
                'setId'       => $row['set_id'],
                'country'     => $row['country'],
                'tier'        => (int) $row['tier'],
                'clubCount'      => (int) $row['club_count'],
                'playerCount'    => (int) $row['player_count'],
                'payloadVersion' => (int) $row['payload_version'],
                'generatedAt'    => new \DateTimeImmutable($row['generated_at']),
            ];
        }, $rows);
    }
}
