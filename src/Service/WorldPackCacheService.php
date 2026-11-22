<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CountryWorldPackCache;
use App\Entity\WorldPack\WorldPackSet;
use App\Repository\CountryWorldPackCacheRepository;
use App\Service\WorldInitializationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and writes the per-tier payload rows that make up a WorldPackSet.
 *
 * ── Why every method here is set-scoped, and must stay that way ──────────────────────────
 *
 * This class used to be keyed `(country, tier)`: `getOrBuild()`, `isCached()`,
 * `forceRebuild()` and `allTiersCached()` all resolved a row through
 * `findOneBy(['country','tier'])`, which was correct for exactly as long as a country had one
 * world. A country now holds a buffer of sets, so that lookup would return an arbitrary one of
 * them — and the damage would be silent: a client would be served tier 1 of one world and tier
 * 2 of another, with every call returning 200 and nothing to see in a log.
 *
 * So those methods are gone rather than patched with an `ORDER BY`. An ordering would make the
 * ambiguity deterministic without making it correct, and would leave the next reader a
 * plausible-looking helper that quietly picks a world.
 *
 * **The cold-build path is gone too.** A device with no claimed set now has its tier built
 * inline by the controller and NOT written here, so there is no insert to race on. That is
 * why the `pg_advisory_lock(hashtext('worldpack:{country}:{tier}'))` serialization this class
 * used to carry has been removed: it existed solely so two concurrent cold callers could not
 * both run the generator and then collide on `uq_country_tier`. With nothing inserted on that
 * path, and with writes now happening only inside a single-flight generation run, there is no
 * longer a race for it to guard — and keeping a lock whose reason has evaporated is how the
 * next person concludes it must still be load-bearing.
 */
class WorldPackCacheService
{
    public function __construct(
        private readonly CountryWorldPackCacheRepository $cacheRepository,
        private readonly EntityManagerInterface          $em,
    ) {}

    /**
     * The stored payload for one tier of $set, or null if that tier is absent or was stamped
     * at a payload version this code no longer serves.
     *
     * The version check is kept even though the set carries its own `payloadVersion`: the two
     * are written at different moments (the set at creation, each row at assembly), so a
     * version bump landing mid-run can leave them disagreeing, and the row's own stamp is the
     * fact about the bytes in hand.
     */
    public function readTier(WorldPackSet $set, int $tier): ?array
    {
        $row = $this->cacheRepository->findForSetAndTier($set, $tier);

        return $row !== null && $row->getPayloadVersion() === WorldInitializationService::WORLD_PACK_VERSION
            ? $row->getPayload()
            : null;
    }

    /** Whether $set already holds a servable payload for $tier. */
    public function hasTier(WorldPackSet $set, int $tier): bool
    {
        return $this->readTier($set, $tier) !== null;
    }

    /**
     * Whether $set holds every tier in $tierNumbers at the current payload version — i.e.
     * whether it is a complete world.
     *
     * This replaced `allTiersCached($country, $tiers)`, and the difference is a correctness
     * fix rather than a rename: the old question was "is this COUNTRY fully cached", so a club
     * could be flagged world-initialized on the strength of packs generated for somebody else.
     *
     * @param int[] $tierNumbers
     */
    public function allTiersPresent(WorldPackSet $set, array $tierNumbers): bool
    {
        $present = $this->cacheRepository->findTiersForSet($set, WorldInitializationService::WORLD_PACK_VERSION);

        return count(array_diff($tierNumbers, $present)) === 0;
    }

    /**
     * Writes $payload as $set's row for $tier, atomically replacing any row already there.
     *
     * The old row is removed only once the new one is ready to persist, so a failure here can
     * never leave the set with a hole. Callers that build the payload themselves
     * (WorldPackTierAssemblyService, the top-up generator) use this; nothing else writes rows.
     */
    public function replaceWithPayload(WorldPackSet $set, int $tier, array $payload): void
    {
        $existing = $this->cacheRepository->findForSetAndTier($set, $tier);
        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();
        }

        $entry = new CountryWorldPackCache($set, $tier, $payload, WorldInitializationService::WORLD_PACK_VERSION);
        $this->em->persist($entry);
        $this->em->flush();
    }

    /**
     * Drops $set's row for $tier once it has been handed to the claiming device.
     *
     * A claimed pack lives on the device, not here: the client writes each tier into its own
     * SQLite as it arrives. Keeping the row would only hold a copy nobody reads. A retried call
     * for a tier whose response was lost finds no row and gets the tier built inline at the
     * current version, which is what any club without a set gets.
     */
    public function consumeTier(WorldPackSet $set, int $tier): void
    {
        $this->cacheRepository->deleteForSetAndTier($set, $tier);
    }

    /** Whether every tier row of $set has been handed out (or never existed). */
    public function isEmpty(WorldPackSet $set): bool
    {
        return $this->cacheRepository->countForSet($set) === 0;
    }

    /** Drops every payload row belonging to $set. The set row itself is left alone. */
    public function deleteBySet(WorldPackSet $set): int
    {
        return $this->cacheRepository->deleteBySet($set);
    }

    /** How many payload rows $country holds, across every set. */
    public function countForCountry(string $country): int
    {
        return $this->cacheRepository->countForCountry($country);
    }

    /** Drops every payload row for $country, claimed sets included. Set rows are left alone. */
    public function deleteAllForCountry(string $country): int
    {
        return $this->cacheRepository->deleteAllForCountry($country);
    }

    /**
     * Drops the payload rows of every set claimed before $before — the retention sweep.
     * See CountryWorldPackCacheRepository::deletePayloadsForSetsClaimedBefore().
     */
    public function prunePayloadsClaimedBefore(\DateTimeImmutable $before): int
    {
        return $this->cacheRepository->deletePayloadsForSetsClaimedBefore($before);
    }
}
