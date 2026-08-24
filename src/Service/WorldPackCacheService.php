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
     * @param callable(): array $generator
     */
    public function getOrBuild(string $country, int $tier, callable $generator): array
    {
        $cached = $this->cacheRepository->findForCountryAndTier($country, $tier);
        if ($cached !== null) {
            if ($cached->getPayloadVersion() === WorldInitializationService::WORLD_PACK_VERSION) {
                return $cached->getPayload();
            }
            // Stale shape — rebuild through the atomic replace path so a failed
            // generator never leaves the cache empty.
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
}
