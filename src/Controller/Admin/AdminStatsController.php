<?php

namespace App\Controller\Admin;

use App\Service\Admin\DashboardStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * JSON feeds for the lazily-loaded admin dashboard panels.
 *
 * These return JSON rather than an EasyAdmin template, so they are hit
 * directly and do not need the `/admin?routeName=…` entry point that
 * template-rendering admin actions require. Same convention as
 * PoolConfigController's `*CountsJson` endpoints.
 */
class AdminStatsController extends AbstractController
{
    public function __construct(
        private readonly DashboardStatsService $stats,
    ) {}

    #[Route('/admin/stats/growth', name: 'admin_stats_growth', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function growth(): JsonResponse
    {
        return $this->json($this->stats->getGrowth());
    }

    #[Route('/admin/stats/leaderboards', name: 'admin_stats_leaderboards', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function leaderboards(): JsonResponse
    {
        return $this->json($this->stats->getLeaderboards());
    }

    #[Route('/admin/stats/pool/{entity}', name: 'admin_stats_pool', requirements: ['entity' => 'players|staff|scouts|agents|world'], methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function pool(string $entity): JsonResponse
    {
        return $this->json($this->stats->getPool($entity));
    }

    #[Route('/admin/stats/refresh', name: 'admin_stats_refresh', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function refresh(Request $request): Response
    {
        $panel = $request->request->get('panel') ?: $request->query->get('panel');
        $panel = is_string($panel) && $panel !== '' ? $panel : null;

        if (!$this->isCsrfTokenValid('admin_stats_refresh', (string) $request->request->get('_token'))) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['success' => false, 'message' => 'Invalid CSRF token.'], 400);
            }

            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->redirect($this->generateUrl('admin'));
        }

        $this->stats->clear($panel);

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => true, 'panel' => $panel ?? 'all']);
        }

        $this->addFlash('success', $panel !== null ? "Refreshed “{$panel}”." : 'Dashboard statistics refreshed.');

        return $this->redirect($this->generateUrl('admin'));
    }
}
