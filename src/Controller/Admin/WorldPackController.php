<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Club;
use App\Entity\User;
use App\Repository\CountryWorldPackCacheRepository;
use App\Repository\LeagueRepository;
use App\Service\WorldInitializationService;
use App\Service\WorldPackCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class WorldPackController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface          $em,
        private readonly CountryWorldPackCacheRepository $cacheRepository,
        private readonly WorldPackCacheService           $worldPackCacheService,
        private readonly WorldInitializationService      $worldInitializationService,
        private readonly LeagueRepository                $leagueRepository,
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

    // ── List tiers for a country (AJAX) ──────────────────────────────────

    #[Route('/admin/worldpack-cache/tiers/{country}', name: 'admin_worldpack_tiers', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function getTiers(string $country): JsonResponse
    {
        $country = strtoupper(trim($country));
        if (strlen($country) !== 2) {
            return $this->json(['error' => 'Invalid country code'], 400);
        }

        $leagues = $this->leagueRepository->findByCountry($country);
        if (empty($leagues)) {
            return $this->json(['error' => "No leagues found for '{$country}'. Seed leagues first."], 404);
        }

        return $this->json([
            'country' => $country,
            'tiers'   => array_map(fn($l) => $l->getTier(), $leagues),
        ]);
    }

    // ── Warm a single tier (AJAX) ─────────────────────────────────────────

    #[Route('/admin/worldpack-cache/warm-tier', name: 'admin_worldpack_warm_tier', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function warmTier(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('worldpack_warm_tier', $request->request->get('_token'))) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        $country = strtoupper(trim($request->request->getString('country')));
        $tier    = (int) $request->request->get('tier', 0);
        $force   = (bool) $request->request->get('force', false);

        if (strlen($country) !== 2 || $tier < 1) {
            return $this->json(['success' => false, 'error' => 'Invalid country or tier'], 400);
        }

        try {
            if ($force) {
                $existing = $this->cacheRepository->findForCountryAndTier($country, $tier);
                if ($existing !== null) {
                    $this->em->remove($existing);
                    $this->em->flush();
                }
            }

            $dummyUser = new User('__warmup__@warmup.local');
            $dummyClub = new Club('__warmup__', $dummyUser);
            $dummyClub->setCountry($country);

            $wasCached = $this->cacheRepository->findForCountryAndTier($country, $tier) !== null;

            $payload = $this->worldPackCacheService->getOrBuild(
                $country,
                $tier,
                fn() => $this->worldInitializationService->buildTierPack($dummyClub, $country, $tier)
            );

            $clubCount   = count($payload['clubs'] ?? []);
            $playerCount = array_sum(array_map(fn($c) => count($c['players'] ?? []), $payload['clubs'] ?? []));

            return $this->json([
                'success'     => true,
                'country'     => $country,
                'tier'        => $tier,
                'clubCount'   => $clubCount,
                'playerCount' => $playerCount,
                'cached'      => $wasCached,
            ]);
        } catch (\Throwable $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
