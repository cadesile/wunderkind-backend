<?php

namespace App\Controller\Admin;

use App\Repository\GameConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly GameConfigRepository $gameConfigRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/admin/settings', name: 'admin_settings')]
    public function index(): Response
    {
        $config = $this->gameConfigRepository->getConfig();

        return $this->render('admin/settings.html.twig', [
            'config' => $config,
        ]);
    }

    #[Route('/admin/settings/save-config', name: 'admin_settings_save_config', methods: ['POST'])]
    public function saveConfig(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('save_config', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_settings');
        }

        $config = $this->gameConfigRepository->getConfig();
        $config->setCliqueRelationshipThreshold((int) $request->request->get('cliqueRelationshipThreshold', 20));
        $config->setCliqueSquadCapPercent((int) $request->request->get('cliqueSquadCapPercent', 30));
        $config->setCliqueMinTenureWeeks((int) $request->request->get('cliqueMinTenureWeeks', 3));
        $this->entityManager->flush();

        $this->addFlash('success', 'Game configuration saved.');
        return $this->redirectToRoute('admin_settings');
    }
}
