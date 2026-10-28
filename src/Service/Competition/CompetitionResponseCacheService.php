<?php

declare(strict_types=1);

namespace App\Service\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionResponseCache;
use App\Repository\Competition\CompetitionResponseCacheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Serves and maintains the prebuilt, viewer-independent body of GET /api/competitions/{id}.
 *
 * Shaped after WorldPackCacheService — an expensive payload, a database-backed row rather
 * than a Symfony pool (see CompetitionResponseCache's docblock for why `var/cache` cannot
 * hold this), and an advisory lock so a cold competition under concurrent load is built once
 * rather than once per request.
 *
 * The cache is **self-validating**. Every read recomputes the competition's state version —
 * one cheap aggregate, against reassembling 64 entrants and 63 fixtures — and treats a
 * mismatch as a miss. Explicit invalidation after a write is therefore an optimisation, not
 * what correctness depends on: a writer that forgets costs one rebuild and cannot serve
 * stale data.
 *
 * **Builds are logged; hits are not.** A build is the expensive event and the rare one, so
 * each one emits `competition.cache.build` at info with the competition id, the elapsed
 * milliseconds and the payload size. That is the half of the picture state cannot give:
 * CompetitionHealthService can report queue depth, lag and cache coverage from rows that
 * already exist, but a hit *rate* is a ratio over requests, and counting requests is what
 * the access log already does — so hit rate is `1 - (builds / GET /api/competitions/{id}
 * requests)` over the same window, with no metrics store to keep. Logging every hit instead
 * would put a log line on the hottest read path in the domain to re-derive a number the
 * access log already carries half of.
 *
 * **Everything here reads and writes through DBAL, never the ORM.** Both reasons are
 * concrete bugs, documented on CompetitionResponseCacheRepository: a failed ORM `flush()`
 * closes the shared EntityManager (which stranded the next round of a cron tick
 * permanently), and the identity map returned a stale managed row from the re-check inside
 * the lock (which made the stampede guard a no-op, so every queued request rebuilt the whole
 * bracket in turn).
 */
class CompetitionResponseCacheService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompetitionResponseCacheRepository $cacheRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * The cached document for this competition, building and storing it if absent or stale.
     *
     * @param callable(): array<string, mixed> $builder
     * @return array<string, mixed>
     */
    public function getOrBuild(ActiveCompetition $activeCompetition, string $stateFingerprint, callable $builder): array
    {
        $hit = $this->readFresh($activeCompetition, $stateFingerprint);
        if ($hit !== null) {
            return $hit;
        }

        return $this->withCompetitionLock($activeCompetition, function () use ($activeCompetition, $stateFingerprint, $builder): array {
            // Re-read inside the lock, from the table rather than from Doctrine. Whoever held
            // the lock before us may have just built exactly the document we were about to.
            // This only works because readFresh() goes through DBAL — the ORM would have
            // handed back the same stale managed instance we already saw above, and every
            // request queued here would rebuild the bracket again.
            $hit = $this->readFresh($activeCompetition, $stateFingerprint);
            if ($hit !== null) {
                // Someone else built it while we waited for the lock. Logged distinctly from
                // a plain hit because it is the stampede guard doing its job, and a rising
                // count here is the signal that a competition is being read faster than it
                // can be built.
                $this->logger->info('competition.cache.build', [
                    'competitionId' => (string) $activeCompetition->getId(),
                    'outcome'       => 'won_by_other',
                ]);

                return $hit;
            }

            return $this->buildAndStore($activeCompetition, $stateFingerprint, $builder, 'on_demand');
        });
    }

    /**
     * Builds and stores unconditionally.
     *
     * Used by `app:competition:cache:warm`. **Only call this where the caller's in-memory
     * state cannot be stale** — a fresh process, or a message handler that loaded nothing
     * else. Calling it from inside a cron tick that has already loaded a competition's
     * entrants will store a document built from those stale entities while stamping it with
     * a freshly-aggregated version, which is precisely the "fresh" stale document this cache
     * must never hold.
     *
     * @param callable(): array<string, mixed> $builder
     * @return array<string, mixed>
     */
    public function rebuild(ActiveCompetition $activeCompetition, string $stateFingerprint, callable $builder): array
    {
        return $this->withCompetitionLock(
            $activeCompetition,
            fn (): array => $this->buildAndStore($activeCompetition, $stateFingerprint, $builder, 'forced'),
        );
    }

    /**
     * Builds, stores and reports. The one place a document is written, so the timing cannot
     * be measured on one path and missed on the other.
     *
     * @param callable(): array<string, mixed> $builder
     * @return array<string, mixed>
     */
    private function buildAndStore(ActiveCompetition $activeCompetition, string $stateFingerprint, callable $builder, string $outcome): array
    {
        $startedAt = microtime(true);
        $payload   = $builder();
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->cacheRepository->upsert($activeCompetition, $payload, $stateFingerprint);

        // A second encode (upsert() does its own), accepted deliberately: it is sub-millisecond
        // against a build measured in hundreds, and payload size in prod is the one signal that
        // would catch per-fixture match detail being re-embedded in a release — the thing
        // app:competition:benchmark guards locally and nothing watched in production.
        $encoded = json_encode($payload);

        $this->logger->info('competition.cache.build', [
            'competitionId' => (string) $activeCompetition->getId(),
            'outcome'       => $outcome,
            'elapsedMs'     => $elapsedMs,
            'bytes'         => $encoded === false ? null : strlen($encoded),
        ]);

        return $payload;
    }

    /**
     * Drops the cached document.
     *
     * Not needed for correctness — the state-version check already makes a superseded row a
     * miss — but it keeps a competition from carrying a document nobody will read, and it is
     * the honest thing to do when a competition is deleted or reset.
     */
    public function invalidate(ActiveCompetition $activeCompetition): void
    {
        $this->cacheRepository->deleteForCompetition($activeCompetition);
    }

    public function isCached(ActiveCompetition $activeCompetition, string $stateFingerprint): bool
    {
        return $this->readFresh($activeCompetition, $stateFingerprint) !== null;
    }

    /**
     * The stored document if it is usable for this state version, else null.
     *
     * A row is usable only when both the document shape and the state it was built from
     * still match.
     *
     * @return array<string, mixed>|null
     */
    private function readFresh(ActiveCompetition $activeCompetition, string $stateFingerprint): ?array
    {
        $row = $this->cacheRepository->findRow($activeCompetition);

        if ($row === null
            || $row['payload_version'] !== CompetitionResponseCache::PAYLOAD_VERSION
            || $row['state_fingerprint'] !== $stateFingerprint
        ) {
            return null;
        }

        try {
            $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // An unreadable row is a miss, not a 500. The caller rebuilds and overwrites it.
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * Serializes rebuilds for one competition.
     *
     * A Postgres advisory lock rather than a row lock, because the row may not exist yet —
     * which is exactly the cold-start case where a stampede hurts most. Failing to take the
     * lock falls through and proceeds anyway: a duplicate build wastes work but produces the
     * same document, and the write is an `ON CONFLICT` upsert, so a concurrent writer
     * collapses into an update rather than erroring. Same trade-off and reasoning as
     * WorldPackCacheService::withTierLock().
     *
     * @template T
     * @param  callable(): T $work
     * @return T
     */
    private function withCompetitionLock(ActiveCompetition $activeCompetition, callable $work): mixed
    {
        $connection = $this->em->getConnection();
        $key        = sprintf('competition_response:%s', $activeCompetition->getId()->toRfc4122());
        $locked     = false;

        try {
            $connection->executeStatement('SELECT pg_advisory_lock(hashtext(:key))', ['key' => $key]);
            $locked = true;
        } catch (\Throwable) {
            // Fall through unlocked — see the docblock.
        }

        try {
            return $work();
        } finally {
            if ($locked) {
                try {
                    $connection->executeStatement('SELECT pg_advisory_unlock(hashtext(:key))', ['key' => $key]);
                } catch (\Throwable) {
                    // Released with the connection at the latest.
                }
            }
        }
    }
}
