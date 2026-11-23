<?php

declare(strict_types=1);

namespace App\Service\WorldPack;

use App\Entity\Club;
use App\Entity\WorldPack\WorldPackSet;
use App\Enum\WorldPack\WorldPackSetStatus;
use App\Repository\WorldPack\WorldPackSetRepository;
use App\Service\WorldInitializationService;
use App\Service\WorldPackCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Owns the reader's half of the world-pack buffer: claiming a set for a club, reading a tier
 * out of it, and counting what a country has in stock.
 *
 * The generation half lives in WorldPackGenerationOrchestrator (admin regenerates) and
 * WorldPackSetTopUpService (the unattended top-up).
 */
class WorldPackSetService
{
    /**
     * How many claimable sets a live country should hold.
     *
     * Two, so that one device claiming a set never leaves the next one waiting on a build:
     * the claim consumes one and the top-up refills behind it, which takes 8-18s across a
     * one-minute cron. One spare would mean any two signups inside that window raced for a
     * single set and the loser paid for a cold build.
     *
     * Overridable per box via StarterConfig::$worldPackSetTarget, so a busy country can be
     * raised without a deploy.
     */
    public const DEFAULT_TARGET_SETS = 2;

    /**
     * How long a set may sit BUILDING before it stops counting towards the buffer.
     *
     * A building set counts as stock so that a burst of inits does not queue one build per
     * init. The bound is what stops that being a trap: a worker killed mid-build leaves a
     * BUILDING row, and without an expiry that one row suppresses every future top-up for its
     * country permanently — the country silently runs dry and the only symptom is a slow init
     * for every player in it.
     */
    public const BUILD_STALE_AFTER = '-30 minutes';

    public function __construct(
        private readonly WorldPackSetRepository $setRepository,
        private readonly WorldPackCacheService  $cacheService,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * Gives $club a set of its own for $country, or null when the country has none ready.
     *
     * Null is a normal answer, not an error: the caller falls back to building live, which is
     * what every client got before sets existed. It must never become a 4xx — a new error code
     * at the first step of game init is unrecognisable to an already-shipped build and the init
     * flow has no client-side escape.
     *
     * **The reconcile read first is not belt-and-braces.** The claim is a raw UPDATE that
     * commits on its own, and the club-side write recording it is a separate Doctrine flush.
     * A failure between the two leaves a set claimed by a club that does not know it — so
     * without this, the retry would claim a SECOND set, leak the first, and draw down the
     * buffer by two for one player.
     */
    public function claimForClub(Club $club, string $country): ?WorldPackSet
    {
        $existing = $club->getWorldPackSet() ?? $this->setRepository->findClaimedByClub($club);
        if ($existing !== null) {
            if ($club->getWorldPackSet() === null) {
                // Recovering a claim whose club-side write was lost. Re-record it rather than
                // claim again; the set is already spent on this club either way.
                $club->setWorldPackSet($existing);
                $this->em->flush();
                $this->logger->warning('world_pack_set.claim.reconciled', [
                    'clubId' => (string) $club->getId(),
                    'setId'  => (string) $existing->getId(),
                ]);
            }

            return $existing;
        }

        $set = $this->setRepository->claimForClub(
            $country,
            WorldInitializationService::WORLD_PACK_VERSION,
            $club,
        );

        if ($set === null) {
            $this->logger->warning('world_pack_set.claim.none_ready', [
                'clubId'  => (string) $club->getId(),
                'country' => $country,
                'hint'    => 'falling back to a live draw and an uncached tier build',
            ]);

            return null;
        }

        $club->setWorldPackSet($set);
        $this->em->flush();

        $this->logger->info('world_pack_set.claim.claimed', [
            'clubId'         => (string) $club->getId(),
            'country'        => $country,
            'setId'          => (string) $set->getId(),
            'setGeneratedAt' => $set->getGeneratedAt()->format('c'),
        ]);

        return $set;
    }

    /** The stored payload for one tier of $set, or null when absent or stale-shaped. */
    public function tierPayload(WorldPackSet $set, int $tier): ?array
    {
        return $this->cacheService->readTier($set, $tier);
    }

    /**
     * How many more sets $country needs to reach $target — zero when it is stocked.
     *
     * Counts READY-and-claimable plus recently-BUILDING, so work already in flight is not
     * queued twice. See BUILD_STALE_AFTER for why the second half is time-bounded.
     */
    public function deficit(string $country, int $target): int
    {
        // Delegated to stock() so the gate and the figures the admin screen reports can never
        // drift apart — they disagreeing is exactly how a starved country came to be reported
        // as stocked.
        return $this->stock($country, $target)['deficit'];
    }

    /**
     * How much of $country's stock is actually claimable right now, and how much is only
     * counted as stock because a build is in flight.
     *
     * One place for the two numbers, because the admin button, the hourly sweep and the
     * handler all have to be able to tell the two apart and they used to each ask separately.
     * `claimable` is the only figure a device can start a game from; `deficit` is what the
     * top-up gate reads, and it counts `building` in. **The dangerous state is
     * `claimable < $target` with `deficit === 0`** — a country with nothing to serve that
     * every top-up path declares stocked.
     *
     * @return array{target:int, claimable:int, building:int, deficit:int, starved:bool}
     */
    public function stock(string $country, int $target): array
    {
        $claimable = $this->setRepository->countReadyUnclaimed($country, WorldInitializationService::WORLD_PACK_VERSION);
        $building  = $this->setRepository->countBuildingSince(
            $country,
            new \DateTimeImmutable(self::BUILD_STALE_AFTER),
        );
        $deficit = max(0, $target - ($claimable + $building));

        return [
            'target'    => $target,
            'claimable' => $claimable,
            'building'  => $building,
            'deficit'   => $deficit,
            'starved'   => $claimable < $target && $deficit === 0,
        ];
    }

    /**
     * Marks every BUILDING set for $country that is older than BUILD_STALE_AFTER as FAILED,
     * and returns how many went. Zero is the normal answer.
     *
     * A set is persisted BUILDING before its first tier is built, so a worker lost mid-build
     * — PHP fatal, OOM kill, container recreate on deploy — leaves a row that nothing is
     * working on and nothing will ever finish or fail. Left alone it stays `building` for
     * ever: it drops out of deficit() after thirty minutes but goes on claiming a line on the
     * admin screen that reads as work in progress, and for those thirty minutes it makes every
     * top-up path report the country stocked while it has nothing claimable.
     *
     * markFailed() logs at ERROR, which matters more than it looks: prod's monolog is
     * fingers_crossed at error level, so an INFO "at target" no-op writes nothing at all,
     * while this leaves a line in prod.log and a reason on the screen. That is the whole
     * point — the failure mode this reaps was invisible, and invisible is what cost the time.
     */
    public function reapStaleBuilding(string $country): int
    {
        $stale = $this->setRepository->findBuildingBefore(
            $country,
            new \DateTimeImmutable(self::BUILD_STALE_AFTER),
        );

        foreach ($stale as $set) {
            $this->markFailed($set, sprintf(
                'Build never completed — stranded BUILDING since %s, longer than %s. '
                . 'No worker is finishing it (a PHP fatal, an OOM kill or a container restart '
                . 'during generation leaves this). Regenerate the country to clear it.',
                $set->getGeneratedAt()->format('Y-m-d H:i:s'),
                trim(self::BUILD_STALE_AFTER, '-'),
            ));
        }

        return count($stale);
    }

    /**
     * Deletes every unclaimed set for $country and returns how many went.
     *
     * CLAIMED sets are deliberately spared, and that is the whole reason this is not a plain
     * "delete the country's sets": a device part-way through initialising still has tiers to
     * fetch from its set, and dropping it would strand that save with a world it can never
     * finish downloading and no way to ask for another.
     */
    public function deleteUnclaimed(string $country): int
    {
        $deleted = $this->setRepository->deleteUnclaimed($country);

        $this->logger->info('world_pack_set.delete_unclaimed', [
            'country' => $country,
            'deleted' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * The admin "Delete all for country": every cache entry for $country goes, and the result
     * says how many rows went and how many remain (zero, unless something wrote one meanwhile).
     *
     * Unclaimed sets are deleted outright, their rows cascading. Claimed sets lose their
     * payload rows but keep the set row, which a club points at and which records which
     * generation that club was served. This used to stop at the unclaimed sets, so every
     * claimed set's tiers stayed in the table — the rows an operator saw surviving the button.
     *
     * **A world a device is still loading is spared** (its club is not yet world-initialised).
     * Those rows are the only copy of that world; deleting them would leave the device unable
     * to finish without tiers from another generation, which is a mixed world. They are
     * reported as `rowsSpared`, and go by themselves within the hour of the load finishing.
     *
     * @return array{setsDeleted:int, claimedSetsCleared:int, rowsBefore:int, rowsDeleted:int, rowsSpared:int, rowsRemaining:int}
     */
    public function deleteAllForCountry(string $country): array
    {
        $rowsBefore = $this->cacheService->countForCountry($country);
        $claimed    = $this->countClaimed($country);
        $spared     = $this->cacheService->countLoadingForCountry($country);

        $this->em->wrapInTransaction(function () use ($country, &$setsDeleted, &$payloadRows): void {
            $setsDeleted = $this->setRepository->deleteUnclaimed($country);
            $payloadRows = $this->cacheService->deleteAllForCountry($country);
        });

        $rowsRemaining = $this->cacheService->countForCountry($country);

        $result = [
            'setsDeleted'        => (int) $setsDeleted,
            'claimedSetsCleared' => $claimed,
            'rowsBefore'         => $rowsBefore,
            'rowsDeleted'        => $rowsBefore - $rowsRemaining,
            'rowsSpared'         => $spared,
            // What survived that SHOULD have gone: anything beyond the spared loading worlds.
            'rowsRemaining'      => max(0, $rowsRemaining - $spared),
        ];

        $this->logger->info('world_pack_set.delete_all_for_country', ['country' => $country] + $result);

        return $result;
    }

    /**
     * How many CLAIMED sets $country holds — the ones `deleteUnclaimed()` deliberately spares.
     *
     * Exists so a regenerate can SAY it spared them. A claimed set keeps its tier payload rows
     * at whatever `payload_version` it was built at, so after a version bump the admin cache
     * table still lists rows at the old version once the rebuild has finished, and an operator
     * reading that reasonably concludes the rebuild was partial. It was not: those rows are
     * unreachable by both filters that matter — the claim (`countReadyUnclaimed`/
     * `claimForClub` both filter on the current version) and the read
     * (`WorldPackCacheService::readTier`). Reporting the count is what makes the difference
     * between "spared on purpose" and "missed" visible without the operator having to know
     * either filter exists.
     */
    public function countClaimed(string $country): int
    {
        return $this->setRepository->countByStatus($country, WorldPackSetStatus::CLAIMED);
    }

    /** Flips $set to READY, stamping readyAt. Only a complete set should reach this. */
    public function markReady(WorldPackSet $set): void
    {
        $set->setStatus(WorldPackSetStatus::READY);
        $set->setReadyAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    /** Flips $set to FAILED with a reason, so the admin screen can say why it is not stock. */
    public function markFailed(WorldPackSet $set, string $reason): void
    {
        $set->setStatus(WorldPackSetStatus::FAILED);
        $set->setErrorMessage($reason);
        $this->em->flush();

        $this->logger->error('world_pack_set.failed', [
            'setId'   => (string) $set->getId(),
            'country' => $set->getCountry(),
            'reason'  => $reason,
        ]);
    }
}
