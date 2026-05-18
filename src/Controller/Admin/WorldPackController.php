<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\CountryWorldPackCacheRepository;
use App\Service\WorldPackCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class WorldPackController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface          $em,
        private readonly CountryWorldPackCacheRepository $cacheRepository,
        private readonly WorldPackCacheService           $worldPackCacheService,
    ) {}

    // ── List ─────────────────────────────────────────────────────────────

    #[Route('/admin/worldpack-cache', name: 'admin_worldpack_cache')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(): Response
    {
        $rawEntries = $this->cacheRepository->findAllOrderedByCountryAndTier();

        $entries   = [];
        $byCountry = [];

        foreach ($rawEntries as $entry) {
            $payload     = $entry->getPayload();
            $clubCount   = count($payload['clubs'] ?? []);
            $playerCount = array_sum(
                array_map(fn($c) => count($c['players'] ?? []), $payload['clubs'] ?? [])
            );

            $entries[] = [
                'id'          => (string) $entry->getId(),
                'country'     => $entry->getCountry(),
                'tier'        => $entry->getTier(),
                'clubCount'   => $clubCount,
                'playerCount' => $playerCount,
                'generatedAt' => $entry->getGeneratedAt(),
            ];

            $byCountry[$entry->getCountry()][$entry->getTier()] = true;
        }

        return $this->render('admin/worldpack_cache.html.twig', [
            'entries'      => $entries,
            'byCountry'    => $byCountry,
            'totalEntries' => count($entries),
        ]);
    }

    // ── Delete single entry ───────────────────────────────────────────────

    #[Route('/admin/worldpack-cache/delete/{id}', name: 'admin_worldpack_delete_entry', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteEntry(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('worldpack_delete_entry', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin_worldpack_cache'));
        }

        $entry = $this->cacheRepository->find($id);

        if ($entry === null) {
            $this->addFlash('warning', "Cache entry {$id} not found — it may have already been deleted.");
            return $this->redirect($this->generateUrl('admin_worldpack_cache'));
        }

        $label = "{$entry->getCountry()} / Tier {$entry->getTier()}";
        $this->em->remove($entry);
        $this->em->flush();

        $this->addFlash('success', "Deleted cache entry: {$label}.");
        return $this->redirect($this->generateUrl('admin_worldpack_cache'));
    }

    // ── Delete all entries for a country ─────────────────────────────────

    #[Route('/admin/worldpack-cache/delete-country', name: 'admin_worldpack_delete_country', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteCountry(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('worldpack_delete_country', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin_worldpack_cache'));
        }

        $country = strtoupper(trim($request->request->getString('country')));

        if (strlen($country) !== 2) {
            $this->addFlash('danger', 'Invalid country code.');
            return $this->redirect($this->generateUrl('admin_worldpack_cache'));
        }

        $deleted = $this->worldPackCacheService->deleteByCountry($country);
        $this->addFlash('success', "Deleted {$deleted} cache entry/entries for {$country}.");

        return $this->redirect($this->generateUrl('admin_worldpack_cache'));
    }

    // ── Regenerate cache for a country ────────────────────────────────────

    #[Route('/admin/worldpack-cache/regenerate', name: 'admin_worldpack_regenerate', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function regenerate(Request $request, KernelInterface $kernel): Response
    {
        if (!$this->isCsrfTokenValid('worldpack_regenerate', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin_worldpack_cache'));
        }

        $country = strtoupper(trim($request->request->getString('country')));

        if (strlen($country) !== 2) {
            $this->addFlash('danger', 'Invalid country code.');
            return $this->redirect($this->generateUrl('admin_worldpack_cache'));
        }

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input  = new ArrayInput([
            'command' => 'app:worldpack:warm',
            'country' => $country,
            '--force' => true,
        ]);
        $output = new BufferedOutput();

        try {
            $exitCode = $application->run($input, $output);
            $text     = trim($output->fetch());

            if ($exitCode === 0) {
                $this->addFlash('success', "Worldpack regenerated for {$country}.");
            } else {
                $this->addFlash('danger', "Regeneration failed for {$country}: {$text}");
            }

            if ($text !== '') {
                $this->addFlash('info', nl2br(htmlspecialchars($text)));
            }
        } catch (\Throwable $e) {
            $this->addFlash('danger', "Error running worldpack command: " . $e->getMessage());
        }

        return $this->redirect($this->generateUrl('admin_worldpack_cache'));
    }
}
