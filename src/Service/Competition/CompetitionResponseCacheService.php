<?php

declare(strict_types=1);

namespace App\Service\Competition;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionResponseCache;
use App\Repository\Competition\CompetitionResponseCacheRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Serves and maintains the prebuilt, viewer-independent body of GET /api/competitions/{id}.
 *
 * Shaped after WorldPackCacheService, which solves the same problem for world packs: an
 * expensive payload, a database-backed row rather than a Symfony pool (see
 * CompetitionResponseCache's docblock for why `var/cache` cannot hold this), and an advisory
 * lock so a cold competition under concurrent load is built once rather than once per
 * request.
 *
 * The cache is **self-validating**. Every read recomputes the competition's state version —
 * one cheap aggregate, against reassembling 64 entrants and 63 fixtures — and treats a
 * mismatch as a miss. Explicit invalidation after a write is therefore an optimisation that
 * keeps the request path from paying for a rebuild, never the thing correctness depends on.
 * A writer that forgets to call invalidate() costs one rebuild; it cannot serve stale data.
 */
class CompetitionResponseCacheService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompetitionResponseCacheRepository $cacheRepository,
    ) {}

    /**
     * The cached document for this competition, building and storing it if absent or stale.
     *
     * @param callable(): array<string, mixed> $builder
     * @return array<string, mixed>
     */
    public function getOrBuild(ActiveCompetition $activeCompetition, int $stateVersion, callable $builder): array
    {
        $existing = $this->cacheRepository->findForCompetition($activeCompetition);
        if ($existing !== null && $existing->isFreshFor($stateVersion)) {
            return $existing->getPayload();
        }

        return $this->withCompetitionLock($activeCompetition, function () use ($activeCompetition, $stateVersion, $builder): array {
            // Re-read inside the lock. Whoever held it before us may have just built exactly
            // the document we were about to build.
            $row = $this->cacheRepository->findForCompetition($activeCompetition);
            if ($row !== null && $row->isFreshFor($stateVersion)) {
                return $row->getPayload();
            }

            return $this->store($activeCompetition, $stateVersion, $builder(), $row)->getPayload();
        });
    }

    /**
     * Builds and stores unconditionally — the prewarm entry point.
     *
     * Called after the cron passes that change a bracket (a round drawn, results published,
     * a competition completed) so the next reader finds the document already built. Takes
     * the same lock as a request-path rebuild, so a warm landing mid-request cannot produce
     * two concurrent builds.
     *
     * @param callable(): array<string, mixed> $builder
     * @return array<string, mixed>
     */
    public function rebuild(ActiveCompetition $activeCompetition, int $stateVersion, callable $builder): array
    {
        return $this->withCompetitionLock($activeCompetition, function () use ($activeCompetition, $stateVersion, $builder): array {
            $row = $this->cacheRepository->findForCompetition($activeCompetition);

            return $this->store($activeCompetition, $stateVersion, $builder(), $row)->getPayload();
        });
    }

    /**
     * Drops the cached document.
     *
     * Not needed for correctness — the state-version check already makes a superseded row a
     * miss — but it keeps a competition from carrying a document nobody will ever read, and
     * it is the honest thing to do when a competition is deleted or reset.
     */
    public function invalidate(ActiveCompetition $activeCompetition): void
    {
        $this->cacheRepository->deleteForCompetition($activeCompetition);
    }

    public function isCached(ActiveCompetition $activeCompetition, int $stateVersion): bool
    {
        $row = $this->cacheRepository->findForCompetition($activeCompetition);

        return $row !== null && $row->isFreshFor($stateVersion);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function store(
        ActiveCompetition $activeCompetition,
        int $stateVersion,
        array $payload,
        ?CompetitionResponseCache $existing,
    ): CompetitionResponseCache {
        $row = $existing !== null
            ? $existing->replace($payload, $stateVersion)
            : new CompetitionResponseCache($activeCompetition, $payload, $stateVersion);

        if ($existing === null) {
            $this->em->persist($row);
        }

        $this->em->flush();

        return $row;
    }

    /**
     * Serializes rebuilds for one competition.
     *
     * A Postgres advisory lock rather than a row lock, because the row may not exist yet —
     * which is precisely the cold-start case where a stampede hurts most. Failure to take
     * the lock falls through and proceeds anyway: a duplicate build wastes work but produces
     * the same document, and the unique index on active_competition_id still prevents two
     * rows. Same trade-off, and the same reasoning, as WorldPackCacheService::withTierLock().
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
            // Fall through unlocked.
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
