<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Serialises the device's deferred writes for ONE club: POST /api/sync and
 * POST /api/league/conclude-season.
 *
 * Why it exists (2026-11-28): the app times a sync out after a few seconds and retries
 * it from its queue, but the server is still working on the first copy. Both copies
 * looked for "have I seen this payload?", both found nothing, and both were applied: two
 * identical VALID week-1 SyncRecords for one club, and with them a second copy of every
 * side effect the payload carries (dividend draws onto the owner's ledger above all).
 * A duplicate check alone cannot close that window; the second request has to WAIT for
 * the first to commit, then find its record and replay it. That is this lock.
 *
 * A Postgres SESSION advisory lock rather than a transaction-scoped one, because sync
 * flushes several times (and conclude-season runs raw-SQL transactions of its own), so
 * there is no single outer transaction to hang an xact lock on. Released in `finally`,
 * and by Postgres itself if the PHP process dies holding it (the connection closes).
 *
 * Per club, not per user: two save slots sync independently. Sync and conclude-season
 * for the same club share the lock on purpose; both move the club row.
 *
 * On any other platform (none in use today) it runs the callback unlocked; the unique
 * indexes remain the backstop there.
 */
class ClubWriteLock
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function run(Club $club, callable $fn): mixed
    {
        $connection = $this->em->getConnection();
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return $fn();
        }

        $key = 'club-write:' . $club->getId()->toRfc4122();
        $connection->executeQuery('SELECT pg_advisory_lock(hashtextextended(:k, 0))', ['k' => $key]);

        try {
            return $fn();
        } finally {
            try {
                $connection->executeQuery('SELECT pg_advisory_unlock(hashtextextended(:k, 0))', ['k' => $key]);
            } catch (\Throwable) {
                // A broken connection has already dropped the lock with the session.
            }
        }
    }
}
