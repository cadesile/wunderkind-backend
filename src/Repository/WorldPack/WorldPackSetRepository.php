<?php

declare(strict_types=1);

namespace App\Repository\WorldPack;

use App\Entity\Club;
use App\Entity\WorldPack\WorldPackSet;
use App\Enum\WorldPack\WorldPackSetStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorldPackSet>
 */
class WorldPackSetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorldPackSet::class);
    }

    /**
     * Atomically claims the oldest claimable set for $country on behalf of $club and
     * returns its id, or null when the country has nothing ready.
     *
     * One statement, deliberately. The obvious implementation — SELECT a candidate, then
     * UPDATE it — hands the same set to two clubs whenever two devices initialise within
     * the same few milliseconds, which is precisely the uniqueness guarantee this whole
     * mechanism exists to provide. `FOR UPDATE SKIP LOCKED` inside the subselect makes a
     * concurrent claimer step over a row already being taken rather than block on it and
     * then lose, so two simultaneous claims get two different sets and a third gets null.
     *
     * Same atomic-conditional-UPDATE idiom as WorldPackTierAssemblyService::claimAssembly()
     * and CompetitionDrawService::claimDraw(); the extra SKIP LOCKED is because here we are
     * choosing a row rather than testing a known one.
     *
     * Oldest-first (`generated_at ASC`) so a set cannot sit unclaimed indefinitely while
     * fresher ones are handed out ahead of it — it would age past a payload version bump
     * and become dead stock.
     *
     * `status = 'ready'` is repeated in the OUTER where so RETURNING is meaningful rather
     * than merely following the subselect, and `starter_pack_json IS NOT NULL` excludes the
     * sets the introducing migration backfilled around pre-existing payloads: those carry no
     * squad, so claiming one would give the club nothing and consume a slot in the buffer.
     */
    public function claimOldestReady(string $country, int $payloadVersion, Club $club): ?string
    {
        $claimed = $this->getEntityManager()->getConnection()->fetchOne(
            <<<'SQL'
            UPDATE world_pack_set
               SET status              = :claimed,
                   claimed_by_club_id  = :clubId,
                   claimed_at          = :now
             WHERE status = :ready
               AND id = (
                     SELECT id
                       FROM world_pack_set
                      WHERE country              = :country
                        AND status               = :ready
                        AND payload_version      = :version
                        AND claimed_by_club_id  IS NULL
                        -- excludes migration-backfilled sets, which have no squad
                        AND starter_pack_json   IS NOT NULL
                      ORDER BY generated_at ASC
                      LIMIT 1
                      FOR UPDATE SKIP LOCKED
                   )
            RETURNING id
            SQL,
            [
                'claimed' => WorldPackSetStatus::CLAIMED->value,
                'clubId'  => (string) $club->getId(),
                'now'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'country' => $country,
                'ready'   => WorldPackSetStatus::READY->value,
                'version' => $payloadVersion,
            ],
        );

        return $claimed === false || $claimed === null ? null : (string) $claimed;
    }

    /**
     * claimOldestReady() plus the entity read, which is what every caller wants.
     *
     * The id round-trip is deliberate rather than clumsy: the claim has to be one SQL
     * statement to be atomic, so the row is already committed-claimed by the time we can
     * hydrate it. `find()` after the UPDATE in the same connection sees it.
     */
    public function claimForClub(string $country, int $payloadVersion, Club $club): ?WorldPackSet
    {
        $id = $this->claimOldestReady($country, $payloadVersion, $club);
        if ($id === null) {
            return null;
        }

        // The UPDATE bypassed the UnitOfWork, so an already-managed copy of this row would
        // still read status=ready. Refresh rather than trust the identity map.
        $set = $this->find($id);
        if ($set !== null) {
            $this->getEntityManager()->refresh($set);
        }

        return $set;
    }

    /** Sets for $country in any of $statuses, newest first — the admin screen's per-country listing. */
    public function findForCountry(string $country, ?array $statuses = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.country = :country')
            ->setParameter('country', $country)
            ->orderBy('s.generatedAt', 'DESC');

        if ($statuses !== null) {
            $qb->andWhere('s.status IN (:statuses)')->setParameter('statuses', $statuses);
        }

        return $qb->getQuery()->getResult();
    }

    public function countByStatus(string $country, WorldPackSetStatus $status): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.country = :country')
            ->andWhere('s.status = :status')
            ->setParameter('country', $country)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** READY, unclaimed, current-version, squad-bearing sets — what the country can actually serve. */
    public function countReadyUnclaimed(string $country, int $payloadVersion): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.country = :country')
            ->andWhere('s.status = :ready')
            ->andWhere('s.payloadVersion = :version')
            ->andWhere('s.claimedByClub IS NULL')
            ->andWhere('s.starterPackJson IS NOT NULL')
            ->setParameter('country', $country)
            ->setParameter('ready', WorldPackSetStatus::READY)
            ->setParameter('version', $payloadVersion)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * BUILDING sets for $country generated since $since — work already in flight.
     *
     * **The time bound is the whole point and must not be dropped.** The top-up counts
     * building sets towards the buffer so that a burst of game inits does not queue one
     * build per init while the first is still draining. Counting them unbounded means a
     * single worker that dies mid-build leaves a BUILDING row that suppresses every future
     * top-up for that country permanently, and the country silently runs dry. Same hazard
     * StarterPoolAuditService::queueIsBusy() guards in the pool, which is also why this is
     * a count of rows rather than a flag.
     */
    public function countBuildingSince(string $country, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.country = :country')
            ->andWhere('s.status = :building')
            ->andWhere('s.generatedAt >= :since')
            ->setParameter('country', $country)
            ->setParameter('building', WorldPackSetStatus::BUILDING)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The set already claimed by $club, if any.
     *
     * Exists for a reconcile read, not for convenience. The claim is a raw UPDATE that
     * commits on its own, and the club-side write that records it is a separate Doctrine
     * flush — so a failure between the two leaves a set claimed by a club that does not
     * know it. Checking here before claiming again recovers that club's own set instead of
     * leaking it and consuming a second one.
     */
    public function findClaimedByClub(Club $club): ?WorldPackSet
    {
        return $this->findOneBy(['claimedByClub' => $club]);
    }

    /**
     * Deletes every unclaimed set for $country (ready, building or failed) and returns the
     * count. CLAIMED sets are deliberately untouched — a device mid-initialisation still
     * has tiers to fetch from its set, and dropping it would strand that save with no
     * client-side escape.
     */
    public function deleteUnclaimed(string $country): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.country = :country')
            ->andWhere('s.claimedByClub IS NULL')
            ->setParameter('country', $country)
            ->getQuery()
            ->execute();
    }

    /** @return WorldPackSet[] Claimed sets whose payloads are older than $before — the prune candidates. */
    public function findClaimedBefore(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :claimed')
            ->andWhere('s.claimedAt IS NOT NULL')
            ->andWhere('s.claimedAt < :before')
            ->setParameter('claimed', WorldPackSetStatus::CLAIMED)
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }

    /**
     * One roll-up row per country for the admin screen: how much claimable stock it holds,
     * how much is building, how much is spoken for, and whether anything is wrong.
     *
     * Raw DBAL and grouped in Postgres, deliberately: `starter_pack_json` and the child
     * payload rows are large, and the screen needs counts, not content. Hydrating sets to
     * count them would pull every starter squad in the system into PHP memory.
     *
     * @return array<string, array{claimable:int, ready:int, building:int, claimed:int, failed:int, staleReady:int, squadless:int, oldestReadyAt:?string}>
     */
    public function summariesByCountry(int $payloadVersion): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
            SELECT country,
                   COUNT(*) FILTER (WHERE status = 'ready'    AND claimed_by_club_id IS NULL
                                      AND payload_version = :v AND starter_pack_json IS NOT NULL) AS claimable,
                   COUNT(*) FILTER (WHERE status = 'ready')    AS ready,
                   COUNT(*) FILTER (WHERE status = 'building') AS building,
                   COUNT(*) FILTER (WHERE status = 'claimed')  AS claimed,
                   COUNT(*) FILTER (WHERE status = 'failed')   AS failed,
                   COUNT(*) FILTER (WHERE status = 'ready' AND payload_version <> :v)      AS stale_ready,
                   COUNT(*) FILTER (WHERE status = 'ready' AND starter_pack_json IS NULL)  AS squadless,
                   MIN(generated_at) FILTER (WHERE status = 'ready') AS oldest_ready_at
              FROM world_pack_set
             GROUP BY country
            SQL,
            ['v' => $payloadVersion],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row['country']] = [
                'claimable'     => (int) $row['claimable'],
                'ready'         => (int) $row['ready'],
                'building'      => (int) $row['building'],
                'claimed'       => (int) $row['claimed'],
                'failed'        => (int) $row['failed'],
                'staleReady'    => (int) $row['stale_ready'],
                'squadless'     => (int) $row['squadless'],
                'oldestReadyAt' => $row['oldest_ready_at'],
            ];
        }

        return $out;
    }

    /**
     * Per-country, per-status counts for every country in one query — the admin screen's
     * header figures. Grouped in Postgres rather than looped in PHP because the payload
     * column on the child rows is large and we want none of it in memory.
     *
     * @return array<string, array<string, int>> country => status => count
     */
    public function countsByCountryAndStatus(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT country, status, COUNT(*) AS n FROM world_pack_set GROUP BY country, status'
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row['country']][$row['status']] = (int) $row['n'];
        }

        return $out;
    }
}
