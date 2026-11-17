<?php

declare(strict_types=1);

namespace App\Service\WorldPack;

use App\Entity\WorldPack\WorldPackGenerationRun;
use App\Entity\WorldPack\WorldPackSet;
use App\Entity\WorldPack\WorldPackGenerationTierRun;
use App\Enum\WorldPack\WorldPackGenerationClubRunStatus;
use App\Enum\WorldPack\WorldPackSetStatus;
use App\Enum\WorldPack\WorldPackGenerationRunStatus;
use App\Enum\WorldPack\WorldPackGenerationTierRunStatus;
use App\Repository\GameConfigRepository;
use App\Repository\LeagueRepository;
use App\Repository\WorldPack\WorldPackGenerationClubRunRepository;
use App\Repository\WorldPack\WorldPackGenerationTierRunRepository;
use App\Service\FixtureGenerationService;
use App\Service\WorldInitializationService;
use App\Service\WorldPackCacheService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Owns the "is this tier done, and if so fold it into the final cache payload" step —
 * called after every club message (see WorldPackGenerationProgressSubscriber) and once
 * more, defensively, whenever a tier has zero clubs (WarmWorldPackTierMessageHandler).
 *
 * A tier is never assembled partial: any club failure blocks assembly entirely (the
 * tier is marked FAILED instead), so a country's cache never silently ships an
 * incomplete league. Completed clubs' snapshots stay on their rows for a "retry failed
 * clubs" action to reuse without redoing already-succeeded work.
 *
 * It also owns the step after that: deciding when a whole WorldPackSet becomes READY.
 * **That hangs off the SET, not the run**, because one admin regenerate builds a country's
 * entire buffer and the sets in it succeed or fail independently — one can go READY while
 * the other is still missing a tier. A run-level check would either hold a finished set back
 * or publish an unfinished one.
 */
class WorldPackTierAssemblyService
{
    public function __construct(
        private readonly WorldPackGenerationClubRunRepository $clubRunRepository,
        private readonly WorldPackGenerationTierRunRepository $tierRunRepository,
        private readonly LeagueRepository $leagueRepository,
        private readonly GameConfigRepository $gameConfigRepository,
        private readonly FixtureGenerationService $fixtureGenerationService,
        private readonly WorldInitializationService $worldInitializationService,
        private readonly WorldPackCacheService $worldPackCacheService,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Recounts completed/failed clubs for $tierRun; if every club is now terminal,
     * attempts the atomic assembly claim and, on success, assembles. Safe to call
     * repeatedly (e.g. once per club-completion event) — a no-op once the tier is
     * already ASSEMBLING/COMPLETED/FAILED, and the claim itself guards against two
     * deliveries both trying to assemble the same tier.
     */
    public function maybeAssemble(WorldPackGenerationTierRun $tierRun): void
    {
        $counts    = $this->clubRunRepository->countByTierRunGroupedByStatus($tierRun);
        $completed = $counts[WorldPackGenerationClubRunStatus::COMPLETED->value] ?? 0;
        $failed    = $counts[WorldPackGenerationClubRunStatus::FAILED->value] ?? 0;

        $tierRun->setCompletedClubCount($completed);
        $tierRun->setFailedClubCount($failed);
        $this->em->flush();

        if ($completed + $failed < $tierRun->getTotalClubCount()) {
            return; // still clubs in flight
        }

        if (!$this->claimAssembly($tierRun)) {
            return; // another delivery already claimed it
        }

        $this->assemble($tierRun);
    }

    /**
     * Claim-lock: an atomic conditional UPDATE, same idiom as
     * CompetitionDrawService::claimDraw() — 0 rows affected means another delivery
     * already claimed assembly for this tier.
     */
    private function claimAssembly(WorldPackGenerationTierRun $tierRun): bool
    {
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
            UPDATE world_pack_generation_tier_run
               SET status = :assembling
             WHERE id = :id
               AND status = :in_progress
            SQL,
            [
                'assembling'  => WorldPackGenerationTierRunStatus::ASSEMBLING->value,
                'id'          => $tierRun->getId()->toRfc4122(),
                'in_progress' => WorldPackGenerationTierRunStatus::IN_PROGRESS->value,
            ],
        );

        if ($affected === 0) {
            return false;
        }

        $this->em->refresh($tierRun);

        return true;
    }

    /**
     * Marks $tierRun terminally FAILED for a reason outside the normal per-club
     * failure path (e.g. its League no longer exists) and propagates to the parent
     * run's own completion check. Public so WarmWorldPackTierMessageHandler can reuse
     * it for the same defensive "League missing" case at dispatch time, rather than
     * duplicating the "mark failed + check run completion" sequence in two places.
     */
    public function markTierFailed(WorldPackGenerationTierRun $tierRun, string $reason): void
    {
        $tierRun->setStatus(WorldPackGenerationTierRunStatus::FAILED);
        $tierRun->setErrorMessage($reason);
        $tierRun->setFinishedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->maybeCompleteRun($tierRun->getRun());

        $set = $tierRun->getSet();
        if ($set !== null) {
            // A failed tier is terminal for its set — reconsider it now rather than leaving it
            // BUILDING until the stale window expires, so the admin screen says FAILED and the
            // top-up stops counting it as stock within the minute.
            $this->maybeReadySet($set);
        }
    }

    private function assemble(WorldPackGenerationTierRun $tierRun): void
    {
        if ($tierRun->getFailedClubCount() > 0) {
            $failedRuns = $this->clubRunRepository->findByTierRunAndStatus($tierRun, WorldPackGenerationClubRunStatus::FAILED);
            $firstError = $failedRuns[0]?->getErrorMessage() ?? 'unknown error';
            $this->markTierFailed($tierRun, sprintf(
                '%d of %d clubs failed: %s%s',
                $tierRun->getFailedClubCount(),
                $tierRun->getTotalClubCount(),
                $firstError,
                count($failedRuns) > 1 ? '...' : '',
            ));

            return;
        }

        $completedRuns = $this->clubRunRepository->findByTierRunAndStatus($tierRun, WorldPackGenerationClubRunStatus::COMPLETED);

        $clubsData  = [];
        $allClubIds = [];
        foreach ($completedRuns as $clubRun) {
            $clubsData[]  = $clubRun->getSnapshotJson();
            $allClubIds[] = (string) $clubRun->getNpcClub()->getId();
        }

        $league = $this->leagueRepository->findByCountryAndTier($tierRun->getCountry(), $tierRun->getTier());
        if ($league === null) {
            // Defensive — the tier run is only ever created for a tier whose League
            // was confirmed to exist at dispatch time (WorldPackGenerationOrchestrator).
            $this->markTierFailed($tierRun, "League no longer exists for country={$tierRun->getCountry()} tier={$tierRun->getTier()}");

            return;
        }

        $gameConfig = $this->gameConfigRepository->getConfig();
        $fixtures   = $this->fixtureGenerationService->generate($allClubIds);
        $sponsorPot = $this->worldInitializationService->rollLeagueSponsors($league, $gameConfig);
        $payload    = $this->worldInitializationService->buildLeagueSnapshot($league, $clubsData, $fixtures, $sponsorPot, $gameConfig);

        $set = $tierRun->getSet();
        if ($set === null) {
            // Defensive: every tier run created by current code carries its set, and the FK is
            // ON DELETE SET NULL, so this means the set was deleted under us (a regenerate, or
            // an admin per-set delete) while this tier was in flight. There is nowhere to put
            // the payload, so fail the tier rather than discard it silently.
            $this->markTierFailed($tierRun, 'Set no longer exists — deleted while this tier was generating');

            return;
        }

        $this->worldPackCacheService->replaceWithPayload($set, $tierRun->getTier(), $payload);

        foreach ($completedRuns as $clubRun) {
            $clubRun->setSnapshotJson(null);
        }
        $tierRun->setStatus(WorldPackGenerationTierRunStatus::COMPLETED);
        $tierRun->setFinishedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->maybeCompleteRun($tierRun->getRun());
        $this->maybeReadySet($set);
    }

    /**
     * Recounts $set's tier runs and, when they are all terminal, flips the set to READY or
     * FAILED. Safe to call repeatedly — both transitions are atomic conditional UPDATEs from
     * 'building', so whichever delivery gets there first wins and the rest are no-ops.
     *
     * A set goes READY only when EVERY tier completed AND it carries a starter squad. The
     * squad is drawn up front by the orchestrator, so in practice the second half of that is
     * already true; it is asserted anyway because a READY set with no squad is exactly the
     * half-built state the whole buffer exists to prevent, and it would be claimed by a real
     * player before anyone noticed.
     */
    public function maybeReadySet(WorldPackSet $set): void
    {
        $tierRuns = $this->tierRunRepository->findBySet($set);
        if ($tierRuns === []) {
            return;
        }

        $anyFailed = false;
        foreach ($tierRuns as $tierRun) {
            if (!in_array($tierRun->getStatus(), [WorldPackGenerationTierRunStatus::COMPLETED, WorldPackGenerationTierRunStatus::FAILED], true)) {
                return; // still a tier in flight
            }
            if ($tierRun->getStatus() === WorldPackGenerationTierRunStatus::FAILED) {
                $anyFailed = true;
            }
        }

        if ($anyFailed) {
            $this->flipSet($set, WorldPackSetStatus::FAILED, 'One or more tiers failed to generate');

            return;
        }

        if ($set->getStarterPackJson() === null) {
            $this->flipSet($set, WorldPackSetStatus::FAILED, 'Every tier generated but the set carries no starter squad');

            return;
        }

        $this->flipSet($set, WorldPackSetStatus::READY, null);
    }

    /** Atomic conditional flip out of 'building' — 0 rows affected means somebody else got there. */
    private function flipSet(WorldPackSet $set, WorldPackSetStatus $to, ?string $error): void
    {
        $affected = $this->em->getConnection()->executeStatement(
            <<<'SQL'
            UPDATE world_pack_set
               SET status        = :to,
                   error_message = :error,
                   ready_at      = CASE WHEN :to = 'ready' THEN :now ELSE ready_at END
             WHERE id     = :id
               AND status = :building
            SQL,
            [
                'to'       => $to->value,
                'error'    => $error,
                'now'      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id'       => $set->getId()->toRfc4122(),
                'building' => WorldPackSetStatus::BUILDING->value,
            ],
        );

        if ($affected > 0) {
            $this->em->refresh($set);
        }
    }

    /**
     * Recounts $run's tier statuses; if every tier is terminal, atomically flips the
     * run to COMPLETED or COMPLETED_WITH_ERRORS. Safe to call repeatedly.
     */
    private function maybeCompleteRun(WorldPackGenerationRun $run): void
    {
        $tierRuns = $this->tierRunRepository->findByRun($run);

        $anyFailed = false;
        foreach ($tierRuns as $tierRun) {
            if (!in_array($tierRun->getStatus(), [WorldPackGenerationTierRunStatus::COMPLETED, WorldPackGenerationTierRunStatus::FAILED], true)) {
                return; // still a tier in flight
            }
            if ($tierRun->getStatus() === WorldPackGenerationTierRunStatus::FAILED) {
                $anyFailed = true;
            }
        }

        $finalStatus = $anyFailed ? WorldPackGenerationRunStatus::COMPLETED_WITH_ERRORS : WorldPackGenerationRunStatus::COMPLETED;

        $this->em->getConnection()->executeStatement(
            <<<'SQL'
            UPDATE world_pack_generation_run
               SET status = :final, finished_at = :now
             WHERE id = :id
               AND status = :in_progress
            SQL,
            [
                'final'       => $finalStatus->value,
                'now'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id'          => $run->getId()->toRfc4122(),
                'in_progress' => WorldPackGenerationRunStatus::IN_PROGRESS->value,
            ],
        );
    }
}
