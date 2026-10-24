<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CountryWorldPackCache;
use App\Service\WorldInitializationService;
use App\Repository\CountryWorldPackCacheRepository;
use Doctrine\ORM\EntityManagerInterface;

class WorldPackCacheService
{
    public function __construct(
        private readonly CountryWorldPackCacheRepository $cacheRepository,
        private readonly EntityManagerInterface          $em,
    ) {}

    /**
     * Returns the cached payload for (country, tier).
     *
     * A row stamped with a payload version other than the current
     * WorldInitializationService::WORLD_PACK_VERSION counts as a miss and is
     * rebuilt. The cache has no TTL, so without that check a pack built before a
     * snapshot-shape change would be served verbatim forever — which is how packs
     * predating the personality work kept handing out all-10s matrices.
     *
     * **A miss is serialized across callers** by a Postgres advisory lock on
     * (country, tier), and the cache is re-checked once the lock is held. Without that,
     * two concurrent `POST /api/initialize/league/{tier}` requests for the same tier both
     * miss, both run the generator — which creates and then DQL-deletes a full tier of
     * Player/Staff rows — and both try to insert, so one of them dies on
     * `uq_country_tier` with a 500 after doing all that work. This went from theoretical
     * to likely when the client started treating an empty or truncated 2xx as a retryable
     * network failure: a backgrounded request is now retried while the first may still be
     * running. The second caller waits, then gets the first one's payload for free.
     *
     * @param callable(): array $generator
     */
    public function getOrBuild(string $country, int $tier, callable $generator): array
    {
        $cached = $this->readFresh($country, $tier);
        if ($cached !== null) {
            return $cached;
        }

        return $this->withTierLock($country, $tier, function () use ($country, $tier, $generator): array {
            // Re-check under the lock: whoever held it before us may have just built this.
            $cached = $this->readFresh($country, $tier);
            if ($cached !== null) {
                return $cached;
            }

            $existing = $this->cacheRepository->findForCountryAndTier($country, $tier);
            if ($existing !== null) {
                // Present but stale-shaped — rebuild through the atomic replace path so a
                // failed generator never leaves the cache empty.
                return $this->forceRebuild($country, $tier, $generator);
            }

            $payload = $generator();

            // buildTierPack deletes player/staff entities via DQL, which bypasses Doctrine's
            // UnitOfWork. Those entities remain ghost-managed; clearing the map before flush
            // prevents Doctrine from trying to re-process them when persisting the cache entry.
            $this->em->clear();

            $entry = new CountryWorldPackCache($country, $tier, $payload, WorldInitializationService::WORLD_PACK_VERSION);
            $this->em->persist($entry);
            $this->em->flush();

            return $payload;
        });
    }

    /**
     * Whether (country, tier) is already cached at the current shape version — i.e. whether
     * a getOrBuild() call would be served from cache or pay for a build. Read before the
     * call, for logging that distinguishes the two.
     */
    public function isCached(string $country, int $tier): bool
    {
        return $this->readFresh($country, $tier) !== null;
    }

    /** The cached payload for (country, tier) if one exists at the current shape version, else null. */
    private function readFresh(string $country, int $tier): ?array
    {
        $cached = $this->cacheRepository->findForCountryAndTier($country, $tier);

        return $cached !== null && $cached->getPayloadVersion() === WorldInitializationService::WORLD_PACK_VERSION
            ? $cached->getPayload()
            : null;
    }

    /**
     * Runs $work holding a Postgres session-level advisory lock for (country, tier).
     *
     * Session-level rather than transaction-level (`pg_advisory_xact_lock`) because the
     * generator flushes and clears the EntityManager several times and must not be wrapped
     * in one long transaction. The lock is released in a `finally`, and Postgres drops any
     * that survive when the connection closes at the end of the request.
     *
     * Degrades to running unlocked if the lock cannot be taken at all — a world pack is
     * worth more than the race guard, and the unique index is still there to catch the
     * collision.
     *
     * @template T
     * @param  callable(): T $work
     * @return T
     */
    private function withTierLock(string $country, int $tier, callable $work): mixed
    {
        $connection = $this->em->getConnection();
        $key        = sprintf('worldpack:%s:%d', $country, $tier);
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

    /**
     * Returns true when every tier in $tierNumbers has a cache entry for $country.
     *
     * @param int[] $tierNumbers
     */
    public function allTiersCached(string $country, array $tierNumbers): bool
    {
        $cached = $this->cacheRepository->findCachedTiers($country, WorldInitializationService::WORLD_PACK_VERSION);
        return count(array_diff($tierNumbers, $cached)) === 0;
    }

    /**
     * Generates a fresh payload for (country, tier) and atomically replaces any
     * existing cache entry. The old entry is only removed AFTER the generator
     * succeeds — so a failed build never leaves the cache empty.
     *
     * @param callable(): array $generator
     */
    public function forceRebuild(string $country, int $tier, callable $generator): array
    {
        $payload = $generator();

        $this->em->clear();

        $existing = $this->cacheRepository->findForCountryAndTier($country, $tier);
        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();
        }

        $entry = new CountryWorldPackCache($country, $tier, $payload, WorldInitializationService::WORLD_PACK_VERSION);
        $this->em->persist($entry);
        $this->em->flush();

        return $payload;
    }

    /**
     * Deletes all cached tiers for $country.
     */
    public function deleteByCountry(string $country): int
    {
        return $this->cacheRepository->deleteByCountry($country);
    }

    /**
     * Atomically replaces the cache entry for (country, tier) with an already-built
     * payload — a thin variant of forceRebuild() for callers (WorldPackTierAssemblyService)
     * that assembled the payload themselves rather than handing over a generator closure.
     * Same guarantee: the old entry is only removed after the new one is ready to persist,
     * so a failure here never leaves the cache empty.
     */
    public function replaceWithPayload(string $country, int $tier, array $payload): void
    {
        $existing = $this->cacheRepository->findForCountryAndTier($country, $tier);
        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();
        }

        $entry = new CountryWorldPackCache($country, $tier, $payload, WorldInitializationService::WORLD_PACK_VERSION);
        $this->em->persist($entry);
        $this->em->flush();
    }
}
