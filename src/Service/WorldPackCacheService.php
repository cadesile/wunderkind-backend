<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CountryWorldPackCache;
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
     * If no cache entry exists, calls $generator(), stores the result, and returns it.
     *
     * @param callable(): array $generator
     */
    public function getOrBuild(string $country, int $tier, callable $generator): array
    {
        $cached = $this->cacheRepository->findForCountryAndTier($country, $tier);
        if ($cached !== null) {
            return $cached->getPayload();
        }

        $payload = $generator();

        // buildTierPack deletes player/staff entities via DQL, which bypasses Doctrine's
        // UnitOfWork. Those entities remain ghost-managed; clearing the map before flush
        // prevents Doctrine from trying to re-process them when persisting the cache entry.
        $this->em->clear();

        $entry = new CountryWorldPackCache($country, $tier, $payload);
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
        $cached = $this->cacheRepository->findCachedTiers($country);
        return count(array_diff($tierNumbers, $cached)) === 0;
    }

    /**
     * Deletes all cached tiers for $country. Used by WarmWorldPackCommand --force.
     */
    public function deleteByCountry(string $country): int
    {
        return $this->cacheRepository->deleteByCountry($country);
    }
}
