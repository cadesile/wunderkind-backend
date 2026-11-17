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
        $ready    = $this->setRepository->countReadyUnclaimed($country, WorldInitializationService::WORLD_PACK_VERSION);
        $building = $this->setRepository->countBuildingSince(
            $country,
            new \DateTimeImmutable(self::BUILD_STALE_AFTER),
        );

        return max(0, $target - ($ready + $building));
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
