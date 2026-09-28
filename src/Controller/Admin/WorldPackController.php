<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\WorldPack\WorldPackGenerationClubRunStatus;
use App\Enum\WorldPack\WorldPackGenerationRunStatus;
use App\Enum\WorldPack\WorldPackGenerationTierRunStatus;
use App\Message\WorldPack\WarmWorldPackClubMessage;
use App\Repository\CountryWorldPackCacheRepository;
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

        $deleted = $this->worldPackCacheService->deleteByCountry($country);
        $this->addFlash('success', "Deleted {$deleted} cache entry/entries for {$country}.");

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_worldpack_cache']));
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

        try {
            $run = $this->orchestrator->startRun($country, $tiers);
        } catch (WorldPackGenerationAlreadyRunningException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 409);
        }

        return $this->json(['success' => true, 'country' => $country, 'runId' => $run->getId()->toRfc4122()]);
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
