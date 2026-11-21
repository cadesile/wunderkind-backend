<?php

declare(strict_types=1);

namespace App\Service\WorldPack;

use App\Entity\WorldPack\WorldPackSet;
use App\Repository\LeagueRepository;
use App\Repository\StarterConfigRepository;
use App\Service\StarterPackService;
use App\Service\WorldInitializationService;
use App\Service\WorldPackCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Adds ONE world pack set to a country, clearing nothing.
 *
 * This is the counterpart to WorldPackGenerationOrchestrator, which rebuilds a country's whole
 * buffer and drops the old stock. The top-up only ever tops up: it is dispatched every time a
 * device starts game initialisation (one set having just been claimed) and by the hourly
 * maintain sweep, so it has to be cheap when there is nothing to do and must never clear
 * anything a device might still be reading.
 *
 * ── Why it does not go through WorldPackGenerationRun ────────────────────────────────────
 *
 * WorldPackGenerationRun's own docblock scopes it to admin-triggered regenerates, with the
 * unattended path calling WorldInitializationService directly. A top-up is the unattended
 * path. Routing it through the run table instead would collide with that table's single-flight
 * partial unique index on (country) — and a top-up fires on every init, so the collision would
 * be the common case rather than a race. It would also mean 8 tier messages and ~160 club
 * messages queued on the `worldpack` transport for routine restocking, sitting in front of an
 * admin who just pressed Regenerate.
 *
 * So a top-up builds one whole set inline in its own handler, on its own transport: the same
 * work `app:worldpack:warm <CC>` already did in a single process, with the same per-tier
 * em->clear() memory discipline. ~20-40s for a full country, and nobody is waiting on it.
 *
 * ── The three guards, and why all three are needed ───────────────────────────────────────
 *
 * 1. The dispatcher (InitializeController) only sends a message when the country is actually
 *    short. Keeps the common path free of queue writes.
 * 2. `topUp()` re-reads the deficit here, on drain, because (1) was evaluated up to a minute
 *    earlier and several inits may have queued behind each other. This is the clamp that makes
 *    the whole pipeline safe to re-run — the same reasoning as
 *    TopUpStarterPoolMessageHandler's.
 * 3. A per-country `pg_try_advisory_lock`, so a burst of 50 inits costs 49 instant no-ops
 *    rather than 49 sequential country generations. `try_`, not the blocking form: a top-up
 *    that cannot get the lock has nothing to wait for, because whoever holds it is doing
 *    exactly the work this one would have done.
 */
class WorldPackSetTopUpService
{
    public function __construct(
        private readonly WorldPackSetService        $setService,
        private readonly StarterPackService         $starterPackService,
        private readonly StarterConfigRepository    $starterConfigRepository,
        private readonly LeagueRepository           $leagueRepository,
        private readonly WorldInitializationService $worldInitializationService,
        private readonly WorldPackCacheService      $cacheService,
        private readonly EntityManagerInterface     $em,
        private readonly LoggerInterface            $logger,
    ) {}

    public function targetSetCount(): int
    {
        $configured = $this->starterConfigRepository->getConfig()->getWorldPackSetTarget();

        return $configured > 0 ? $configured : WorldPackSetService::DEFAULT_TARGET_SETS;
    }

    /** Whether $country is short of its buffer target — the dispatcher's cheap gate. */
    public function needsTopUp(string $country): bool
    {
        return $this->setService->deficit($country, $this->targetSetCount()) > 0;
    }

    /**
     * The country's stock broken into claimable vs in-flight, against the configured target.
     *
     * Exists so a caller can tell the two ways of being "not short" apart. `needsTopUp()`
     * answers false both for a country holding its full buffer and for one holding nothing a
     * device can use while builds are counted in flight — and reporting those two the same way
     * is the defect this is here to make impossible. See WorldPackSetService::stock().
     *
     * @return array{target:int, claimable:int, building:int, deficit:int, starved:bool}
     */
    public function stock(string $country): array
    {
        return $this->setService->stock($country, $this->targetSetCount());
    }

    /** Marks $country's stranded BUILDING sets FAILED. See WorldPackSetService::reapStaleBuilding(). */
    public function reapStaleBuilding(string $country): int
    {
        return $this->setService->reapStaleBuilding($country);
    }

    /**
     * Builds up to $max sets for $country, stopping as soon as it is at target.
     *
     * @return WorldPackSet[] The sets actually built — empty is the normal, healthy answer.
     */
    public function topUp(string $country, int $max = 1): array
    {
        $target = $this->targetSetCount();

        $stock = $this->setService->stock($country, $target);

        if ($stock['deficit'] <= 0) {
            if ($stock['starved']) {
                // Not the healthy no-op. The country has nothing a device can claim and is
                // only "at target" because BUILDING rows are counted in flight — and a
                // stranded one of those is exactly how this goes quiet for half an hour. At
                // WARNING so prod's fingers_crossed monolog actually writes it; the healthy
                // case stays at INFO, where it is correctly invisible.
                $this->logger->warning('world_pack_set.topup.starved', [
                    'country'   => $country,
                    'target'    => $target,
                    'claimable' => $stock['claimable'],
                    'building'  => $stock['building'],
                    'hint'      => 'no claimable set; counted at target only by builds in flight',
                ]);

                return [];
            }

            $this->logger->info('world_pack_set.topup.at_target', [
                'country' => $country,
                'target'  => $target,
            ]);

            return [];
        }

        $lockKey = sprintf('worldpackset:%s', $country);
        if (!$this->tryLock($lockKey)) {
            $this->logger->info('world_pack_set.topup.locked', [
                'country' => $country,
                'hint'    => 'another top-up for this country is already building',
            ]);

            return [];
        }

        try {
            $built = [];

            for ($i = 0; $i < $max; $i++) {
                // Re-read inside the loop: the first set we build closes the deficit, and a
                // --count=5 run against a country one short must not build five.
                if ($this->setService->deficit($country, $target) <= 0) {
                    break;
                }

                $set = $this->buildOneSet($country);
                if ($set === null) {
                    break;
                }

                $built[] = $set;
            }

            return $built;
        } finally {
            $this->unlock($lockKey);
        }
    }

    /**
     * Rebuilds $country's buffer synchronously: clears the unclaimed stock, then builds
     * $count sets unconditionally.
     *
     * This is what `app:worldpack:warm` now means. It bypasses the deficit check on purpose —
     * an operator asking for a rebuild is saying the current stock is wrong, not that it is
     * missing. Claimed sets are still spared; a device mid-initialisation keeps its world.
     *
     * Synchronous, in one process, like the command it replaces. The admin screen's
     * Regenerate button takes the async per-club-tracked route through
     * WorldPackGenerationOrchestrator instead, because somebody is sitting watching it.
     *
     * @return WorldPackSet[]
     */
    public function rebuild(string $country, ?int $count = null): array
    {
        $count ??= $this->targetSetCount();

        $this->setService->deleteUnclaimed($country);

        $built = [];
        for ($i = 0; $i < $count; $i++) {
            $set = $this->buildOneSet($country);
            if ($set === null) {
                break;
            }
            $built[] = $set;
        }

        $this->logger->info('world_pack_set.rebuild.done', [
            'country'   => $country,
            'requested' => $count,
            'built'     => count($built),
        ]);

        return $built;
    }

    /** One complete set, built now. Public so the CLI can drive it without the deficit gate. */
    public function generateSet(string $country): ?WorldPackSet
    {
        return $this->buildOneSet($country);
    }

    /**
     * Generates a squad, builds every tier, and flips the set to READY.
     *
     * Returns null only when the country has no League rows to build tiers from, or when tier
     * generation itself fails. The squad half can no longer fail: it is generated from tier
     * config rather than drawn from a shared pool, so there is no supply to be short of.
     */
    private function buildOneSet(string $country): ?WorldPackSet
    {
        $leagues = $this->leagueRepository->findByCountry($country);
        $tiers   = array_map(static fn($league) => $league->getTier(), $leagues);

        if ($tiers === []) {
            $this->logger->warning('world_pack_set.topup.no_leagues', ['country' => $country]);

            return null;
        }

        $bottomTier = $this->starterPackService->resolveStarterTier($country);

        $pack = $this->starterPackService->generatePack($country, $bottomTier);

        $set = new WorldPackSet(
            $country,
            WorldInitializationService::WORLD_PACK_VERSION,
            $pack,
            count($tiers),
        );
        $this->em->persist($set);
        $this->em->flush();

        $setId    = (string) $set->getId();
        $startedAt = microtime(true);

        try {
            foreach ($tiers as $tier) {
                // buildTierPack() generates a whole league's squads in memory, so the
                // EntityManager is cleared between tiers — the same discipline
                // app:worldpack:warm needs to get through eight tiers of an 18-club country
                // without exhausting memory. The set is re-read after each clear because the
                // clear detaches it.
                $payload = $this->worldInitializationService->buildTierPack(null, $country, $tier);

                $set = $this->em->find(WorldPackSet::class, $setId);
                if ($set === null) {
                    // Deleted under us by a regenerate or an admin action. Stop; the payloads
                    // went with it via ON DELETE CASCADE.
                    $this->logger->warning('world_pack_set.topup.set_vanished', [
                        'country' => $country,
                        'setId'   => $setId,
                    ]);

                    return null;
                }

                $this->cacheService->replaceWithPayload($set, $tier, $payload);
                $this->em->clear();
            }

            $set = $this->em->find(WorldPackSet::class, $setId);
            if ($set === null) {
                return null;
            }

            $this->setService->markReady($set);

            $this->logger->info('world_pack_set.topup.ready', [
                'country'    => $country,
                'setId'      => $setId,
                'tiers'      => count($tiers),
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return $set;
        } catch (\Throwable $e) {
            // Mark it FAILED and KEEP the row. Keeping it is no longer about salvaging a
            // consumed pool — nothing was consumed — but FAILED still earns its place: it takes
            // the set out of the buffer count immediately and puts the reason on the admin
            // screen, where leaving it BUILDING would suppress top-ups for this country until
            // the stale window expired.
            $set = $this->em->find(WorldPackSet::class, $setId);
            if ($set !== null) {
                $this->setService->markFailed($set, 'Tier generation failed: ' . $e->getMessage());
            }

            $this->logger->error('world_pack_set.topup.failed', [
                'country' => $country,
                'setId'   => $setId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function tryLock(string $key): bool
    {
        try {
            return (bool) $this->em->getConnection()->fetchOne(
                'SELECT pg_try_advisory_lock(hashtext(:key))',
                ['key' => $key],
            );
        } catch (\Throwable) {
            // No lock available is not a reason to skip the work — the deficit re-read above
            // is the correctness guard; the lock is only there to stop waste.
            return true;
        }
    }

    private function unlock(string $key): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                'SELECT pg_advisory_unlock(hashtext(:key))',
                ['key' => $key],
            );
        } catch (\Throwable) {
            // Released with the connection at the latest.
        }
    }
}
