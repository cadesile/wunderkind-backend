<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\WorldPack\WorldPackGenerationClubRunStatus;
use App\Enum\WorldPack\WorldPackGenerationRunStatus;
use App\Enum\WorldPack\WorldPackGenerationTierRunStatus;
use App\Message\WorldPack\WarmWorldPackClubMessage;
use App\Enum\WorldPack\WorldPackSetStatus;
use App\Message\WorldPack\TopUpWorldPackSetMessage;
use App\Repository\CountryWorldPackCacheRepository;
use App\Repository\WorldPack\WorldPackSetRepository;
use App\Service\WorldInitializationService;
use App\Service\WorldPack\WorldPackSetService;
use App\Service\WorldPack\WorldPackSetTopUpService;
use App\Repository\LeagueRepository;
use App\Repository\WorldPack\WorldPackGenerationClubRunRepository;
use App\Repository\WorldPack\WorldPackGenerationRunRepository;
use App\Repository\WorldPack\WorldPackGenerationTierRunRepository;
use App\Service\WorldPack\WorldPackGenerationAlreadyRunningException;
use App\Service\WorldPack\WorldPackGenerationOrchestrator;
use App\Service\WorldPackCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class WorldPackController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface          $em,
        private readonly CountryWorldPackCacheRepository $cacheRepository,
        private readonly WorldPackSetRepository          $setRepository,
        private readonly WorldPackSetService             $setService,
        private readonly WorldPackSetTopUpService        $topUpService,
        private readonly WorldPackCacheService           $worldPackCacheService,
        private readonly LeagueRepository                $leagueRepository,
        private readonly WorldPackGenerationOrchestrator      $orchestrator,
        private readonly WorldPackGenerationRunRepository     $runRepository,
        private readonly WorldPackGenerationTierRunRepository $tierRunRepository,
        private readonly WorldPackGenerationClubRunRepository $clubRunRepository,
        private readonly MessageBusInterface                  $messageBus,
    ) {}

    // ── Delete single entry ───────────────────────────────────────────────

    #[Route('/admin/worldpack-cache/delete/{id}', name: 'admin_worldpack_delete_entry', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteEntry(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('worldpack_delete_entry', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_worldpack_cache']));
        }

        $entry = $this->cacheRepository->find($id);

        if ($entry === null) {
            $this->addFlash('warning', "Cache entry {$id} not found — it may have already been deleted.");
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_worldpack_cache']));
        }

        $label = "{$entry->getCountry()} / Tier {$entry->getTier()}";
        $this->em->remove($entry);
        $this->em->flush();

        $this->addFlash('success', "Deleted cache entry: {$label}.");
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_worldpack_cache']));
    }

    // ── Delete all entries for a country ─────────────────────────────────

    #[Route('/admin/worldpack-cache/delete-country', name: 'admin_worldpack_delete_country', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteCountry(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('worldpack_delete_country', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_worldpack_cache']));
        }

        $country = strtoupper(trim($request->request->getString('country')));

        if (strlen($country) !== 2) {
            $this->addFlash('danger', 'Invalid country code.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_worldpack_cache']));
        }

        // UNCLAIMED sets only. A claimed set belongs to a device that may still be part-way
        // through downloading its tiers; deleting it would leave that save with a world it can
        // never finish and no way to ask for another. The flash says how many were spared so
        // this does not read as a partial failure.
        $kept    = $this->setRepository->countByStatus($country, WorldPackSetStatus::CLAIMED);
        $deleted = $this->setService->deleteUnclaimed($country);

        $this->addFlash('success', $kept > 0
            ? "Deleted {$deleted} unclaimed set(s) for {$country}. Kept {$kept} claimed set(s) — those belong to clubs still initialising."
            : "Deleted {$deleted} unclaimed set(s) for {$country}.");

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_worldpack_cache']));
    }

    // ── Delete one set ───────────────────────────────────────────────────

    #[Route('/admin/worldpack-cache/delete-set/{id}', name: 'admin_worldpack_delete_set', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteSet(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('worldpack_delete_set', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_worldpack_cache']));
        }

        $set = $this->setRepository->find($id);

        if ($set === null) {
            $this->addFlash('warning', "Set {$id} not found — it may have already been deleted.");
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_worldpack_cache']));
        }

        // Refuse a claimed set unless explicitly forced, for the same reason delete-country
        // spares them: there is a device depending on it. `force` is here because an operator
        // sometimes genuinely needs to clear a set claimed by an abandoned save, and the
        // alternative is editing the database by hand.
        if ($set->getStatus() === WorldPackSetStatus::CLAIMED && !$request->request->getBoolean('force')) {
            $club = $set->getClaimedByClub();
            $this->addFlash('warning', sprintf(
                'Set %s is claimed%s and was NOT deleted — that club may still be fetching its tiers. Re-submit with force to delete it anyway.',
                $id,
                $club !== null ? ' by ' . $club->getName() : '',
            ));

            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_worldpack_cache']));
        }

        $label = sprintf('%s / %s', $set->getCountry(), $set->getStatus()->value);
        // Its tier payload rows go with it via ON DELETE CASCADE.
        $this->em->remove($set);
        $this->em->flush();

        $this->addFlash('success', "Deleted set {$label}.");

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_worldpack_cache']));
    }

    // ── Top one country up (+1 set, clears nothing) ──────────────────────

    #[Route('/admin/worldpack-cache/top-up', name: 'admin_worldpack_top_up', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function topUp(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('worldpack_top_up', $request->request->get('_token'))) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        $country = strtoupper(trim($request->request->getString('country')));
        if (strlen($country) !== 2) {
            return $this->json(['success' => false, 'error' => 'Invalid country code'], 400);
        }

        // A stranded BUILDING row is counted as stock by deficit(), so reap before reading the
        // gate: an operator pressing this button is the moment to turn a build nothing is
        // working on into a FAILED row with a reason, rather than let it go on suppressing
        // top-ups for the rest of its thirty-minute window.
        $reaped = $this->topUpService->reapStaleBuilding($country);

        $stock = $this->topUpService->stock($country);

        // 🔴 THE TWO WAYS OF NOT BEING SHORT ARE NOT THE SAME ANSWER, AND REPORTING THEM
        // ALIKE IS WHAT THIS BLOCK EXISTS TO PREVENT. deficit() counts BUILDING sets towards
        // the buffer (correctly — it stops a burst of inits queueing one build each), so a
        // country with ZERO claimable sets and two builds in flight is "not short". It is also
        // unable to serve a single new game. The screen's own stock column says `0/2 SHORT`
        // and enables this button on exactly that test, so answering `success: true,
        // queued: false, reason: already at target` told an operator the opposite of what the
        // row beside the button said — and wrote nothing. That no-op silently invalidated
        // about three hours of device testing on 2026-11-20. A refusal that names the numbers
        // is worth far more than a success that means nothing.
        if ($stock['starved']) {
            return $this->json([
                'success'   => false,
                'queued'    => false,
                'country'   => $country,
                'error'     => sprintf(
                    '%s has %d claimable set(s) of %d and %d build(s) counted in flight, so the '
                    . 'top-up gate reports it stocked and NOTHING was queued. Either wait for '
                    . 'those builds to finish or to go stale (%s), or use Regenerate to clear '
                    . 'them now.',
                    $country,
                    $stock['claimable'],
                    $stock['target'],
                    $stock['building'],
                    trim(WorldPackSetService::BUILD_STALE_AFTER, '-'),
                ),
                'reason'    => 'starved: no claimable set, counted at target by builds in flight',
                'claimable' => $stock['claimable'],
                'building'  => $stock['building'],
                'target'    => $stock['target'],
                'reaped'    => $reaped,
            ], 409);
        }

        if ($stock['deficit'] <= 0) {
            return $this->json([
                'success'   => true,
                'queued'    => false,
                'country'   => $country,
                'reason'    => 'already at target',
                'claimable' => $stock['claimable'],
                'building'  => $stock['building'],
                'target'    => $stock['target'],
                'reaped'    => $reaped,
            ]);
        }

        // Queued, not built inline: a set is 20-40s of generation and an admin request must
        // not hold a worker open for it. The same message the init endpoint dispatches, so the
        // handler's deficit re-read makes a double-click harmless.
        $this->messageBus->dispatch(new TopUpWorldPackSetMessage($country));

        return $this->json([
            'success'   => true,
            'queued'    => true,
            'country'   => $country,
            'claimable' => $stock['claimable'],
            'building'  => $stock['building'],
            'target'    => $stock['target'],
            'reaped'    => $reaped,
        ]);
    }

    // ── Set stock per country (AJAX, for the top-up button's poll) ────────

    #[Route('/admin/worldpack-cache/set-status/{country}', name: 'admin_worldpack_set_status', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function setStatus(string $country): JsonResponse
    {
        $country = strtoupper(trim($country));

        // A top-up creates no WorldPackGenerationRun (see WorldPackSetTopUpService), so the
        // existing run-status poll cannot see it. This is the equivalent for that path.
        $sets = [];
        foreach ($this->setRepository->findForCountry($country) as $set) {
            $sets[] = [
                'id'               => (string) $set->getId(),
                'status'           => $set->getStatus()->value,
                'payloadVersion'   => $set->getPayloadVersion(),
                'stale'            => $set->getPayloadVersion() !== WorldInitializationService::WORLD_PACK_VERSION,
                'tierCount'        => $set->getTierCount(),
                'hasStarterSquad'  => $set->getStarterPackJson() !== null,
                'generatedAt'      => $set->getGeneratedAt()->format('c'),
                'readyAt'          => $set->getReadyAt()?->format('c'),
                'claimedAt'        => $set->getClaimedAt()?->format('c'),
                'claimedBy'        => $set->getClaimedByClub()?->getName(),
                'errorMessage'     => $set->getErrorMessage(),
            ];
        }

        return $this->json([
            'country'   => $country,
            'target'    => $this->topUpService->targetSetCount(),
            'claimable' => $this->setRepository->countReadyUnclaimed($country, WorldInitializationService::WORLD_PACK_VERSION),
            'sets'      => $sets,
        ]);
    }

    // ── Regenerate country cache (async, per-club progress tracking) ─────

    #[Route('/admin/worldpack-cache/regenerate-country', name: 'admin_worldpack_regenerate_country', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function regenerateCountry(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('worldpack_regenerate_country', $request->request->get('_token'))) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        $country = strtoupper(trim($request->request->getString('country')));
        if (strlen($country) !== 2) {
            return $this->json(['success' => false, 'error' => 'Invalid country code'], 400);
        }

        $leagues = $this->leagueRepository->findByCountry($country);
        if (empty($leagues)) {
            return $this->json(['success' => false, 'error' => "No leagues found for '{$country}'. Seed leagues first."], 404);
        }
        $tiers = array_map(static fn ($l) => $l->getTier(), $leagues);

        $setCountRaw = $request->request->get('setCount');
        $setCount    = $setCountRaw !== null && $setCountRaw !== '' ? max(1, (int) $setCountRaw) : null;

        try {
            $result = $this->orchestrator->startRun($country, $tiers, $setCount);
        } catch (WorldPackGenerationAlreadyRunningException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 409);
        }

        $run         = $result['run'];
        $startedSets = count($result['sets']);

        $keptClaimed = $result['keptClaimedSets'];

        return $this->json([
            'success'       => true,
            'country'       => $country,
            'runId'         => $run->getId()->toRfc4122(),
            'requestedSets' => $result['requestedSets'],
            'startedSets'   => $startedSets,
            // Kept, though it can no longer be true: the squads are generated, so a run always
            // starts every set it was asked for. The key stays because the admin JS reads it and
            // removing it would be a shape change for no gain.
            'partial'       => $startedSets < $result['requestedSets'],
            // ── WHAT THE CLEAR ACTUALLY DID, SAID RATHER THAN LEFT TO BE INFERRED ───────────
            //
            // ADDITIVE. `delete-country` has always reported its spared claimed sets in a flash
            // message; this action cleared the same way and reported nothing, so the only
            // evidence an operator had was the cache table still listing rows at the old
            // payload version once the run read COMPLETED. That reads as a partial rebuild and
            // was reported as one (2026-11-22). It is not: a spared set is unclaimable and its
            // rows are unservable — both filters are named in the orchestrator's comment.
            'clearedSets'     => $result['clearedSets'],
            'keptClaimedSets' => $keptClaimed,
            'note'            => $keptClaimed > 0
                ? sprintf(
                    'Cleared %d unclaimed set(s). Kept %d claimed set(s) — a device mid-'
                    . 'initialisation still needs its tiers. Their payload rows stay in the '
                    . 'cache table at the version they were built at and are NEVER served: the '
                    . 'claim and the per-row read both filter on pack version %d. The hourly '
                    . 'app:worldpack:maintain prunes them once the claim is old enough.',
                    $result['clearedSets'],
                    $keptClaimed,
                    WorldInitializationService::WORLD_PACK_VERSION,
                )
                : sprintf('Cleared %d unclaimed set(s). No claimed sets to spare.', $result['clearedSets']),
        ]);
    }

    // ── Poll current/latest run status for a country (AJAX) ──────────────

    #[Route('/admin/worldpack-cache/run-status/{country}', name: 'admin_worldpack_run_status', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function runStatus(string $country): JsonResponse
    {
        $country = strtoupper(trim($country));
        $run     = $this->runRepository->findLatestForCountry($country);

        if ($run === null) {
            return $this->json(['country' => $country, 'run' => null]);
        }

        $tiers = [];
        foreach ($this->tierRunRepository->findByRun($run) as $tierRun) {
            $clubs = array_map(static fn ($clubRun) => [
                'name'       => $clubRun->getClubName(),
                'status'     => $clubRun->getStatus()->value,
                'startedAt'  => $clubRun->getStartedAt()?->format(\DateTimeInterface::ATOM),
                'finishedAt' => $clubRun->getFinishedAt()?->format(\DateTimeInterface::ATOM),
                'error'      => $clubRun->getErrorMessage(),
            ], $this->clubRunRepository->findByTierRun($tierRun));

            $tiers[] = [
                'tierRunId'      => $tierRun->getId()->toRfc4122(),
                'tier'           => $tierRun->getTier(),
                'status'         => $tierRun->getStatus()->value,
                'totalClubs'     => $tierRun->getTotalClubCount(),
                'completedClubs' => $tierRun->getCompletedClubCount(),
                'failedClubs'    => $tierRun->getFailedClubCount(),
                'error'          => $tierRun->getErrorMessage(),
                'clubs'          => $clubs,
            ];
        }

        return $this->json([
            'country' => $country,
            'run'     => [
                'id'         => $run->getId()->toRfc4122(),
                'status'     => $run->getStatus()->value,
                'startedAt'  => $run->getStartedAt()?->format(\DateTimeInterface::ATOM),
                'finishedAt' => $run->getFinishedAt()?->format(\DateTimeInterface::ATOM),
                'tiers'      => $tiers,
            ],
        ]);
    }

    // ── Retry every FAILED club within one tier run (AJAX) ────────────────

    #[Route('/admin/worldpack-cache/retry-failed-clubs/{tierRunId}', name: 'admin_worldpack_retry_failed_clubs', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function retryFailedClubs(string $tierRunId, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('worldpack_retry_failed_clubs', $request->request->get('_token'))) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        $tierRun = $this->tierRunRepository->find($tierRunId);
        if ($tierRun === null) {
            return $this->json(['success' => false, 'error' => 'Tier run not found'], 404);
        }

        $failedRuns = $this->clubRunRepository->findByTierRunAndStatus($tierRun, WorldPackGenerationClubRunStatus::FAILED);
        if ($failedRuns === []) {
            return $this->json(['success' => true, 'retried' => 0]);
        }

        foreach ($failedRuns as $clubRun) {
            $clubRun->setStatus(WorldPackGenerationClubRunStatus::PENDING);
            $clubRun->setClaimedAt(null);
            $clubRun->setErrorMessage(null);
        }

        // The tier (and its run) were marked terminal when the failure was first
        // discovered — reopen both so the assembly claim (`WHERE status =
        // 'in_progress'`) can succeed again once the retried clubs finish.
        $tierRun->setStatus(WorldPackGenerationTierRunStatus::IN_PROGRESS);
        $tierRun->setErrorMessage(null);
        $tierRun->setFinishedAt(null);

        $run = $tierRun->getRun();
        if ($run->getStatus() === WorldPackGenerationRunStatus::COMPLETED_WITH_ERRORS) {
            $run->setStatus(WorldPackGenerationRunStatus::IN_PROGRESS);
            $run->setFinishedAt(null);
        }

        $this->em->flush();

        foreach ($failedRuns as $clubRun) {
            $this->messageBus->dispatch(new WarmWorldPackClubMessage($clubRun->getId()->toRfc4122()));
        }

        return $this->json(['success' => true, 'retried' => count($failedRuns)]);
    }
}
