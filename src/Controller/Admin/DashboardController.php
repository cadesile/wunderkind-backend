<?php

namespace App\Controller\Admin;

use App\Entity\Academy;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Repository\GameConfigRepository;
use App\Service\EconomicService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private EntityManagerInterface $em,
        private GameConfigRepository $gameConfigRepository,
    ) {}

    // ── Dashboard ─────────────────────────────────────────────────────────

    public function index(): Response
    {
        $conn = $this->em->getConnection();

        $byNationality = $conn->fetchAllAssociative(
            'SELECT nationality, COUNT(*) AS cnt FROM player WHERE academy_id IS NULL GROUP BY nationality ORDER BY cnt DESC LIMIT 15'
        );
        $byPosition = $conn->fetchAllAssociative(
            'SELECT position, COUNT(*) AS cnt FROM player WHERE academy_id IS NULL GROUP BY position ORDER BY cnt DESC'
        );
        $byAge = $conn->fetchAllAssociative(
            'SELECT (YEAR(CURDATE()) - YEAR(date_of_birth)) AS age, COUNT(*) AS cnt FROM player WHERE academy_id IS NULL GROUP BY age ORDER BY age'
        );

        return $this->render('admin/dashboard.html.twig', [
            'stats' => [
                'users'           => $this->em->getRepository(User::class)->count([]),
                'academies'       => $this->em->getRepository(Academy::class)->count([]),
                'syncs'           => $this->em->getRepository(SyncRecord::class)->count([]),
                'invalidSyncs'    => $this->em->getRepository(SyncRecord::class)->count(['isValid' => false]),
                'poolPlayers'     => (int) $conn->fetchOne('SELECT COUNT(*) FROM player WHERE academy_id IS NULL'),
                'assignedPlayers' => (int) $conn->fetchOne('SELECT COUNT(*) FROM player WHERE academy_id IS NOT NULL'),
                'poolStaff'       => (int) $conn->fetchOne('SELECT COUNT(*) FROM staff WHERE academy_id IS NULL'),
                'assignedStaff'   => (int) $conn->fetchOne('SELECT COUNT(*) FROM staff WHERE academy_id IS NOT NULL'),
            ],
            'charts' => [
                'byNationality' => $byNationality,
                'byPosition'    => $byPosition,
                'byAge'         => $byAge,
            ],
        ]);
    }

    // ── Settings ──────────────────────────────────────────────────────────

    #[Route('/admin/settings', name: 'admin_settings')]
    #[IsGranted('ROLE_ADMIN')]
    public function settings(): Response
    {
        return $this->render('admin/settings.html.twig', [
            'config' => $this->gameConfigRepository->getConfig(),
        ]);
    }

    #[Route('/admin/settings/save-config', name: 'admin_settings_save_config', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
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
        $this->em->flush();

        $this->addFlash('success', 'Game configuration saved.');
        return $this->redirectToRoute('admin_settings');
    }

    // ── Developer Tools ───────────────────────────────────────────────────

    #[Route('/admin/developer-tools/trigger-age21', name: 'admin_trigger_age21', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function triggerAge21Deletion(Request $request, EconomicService $economicService): Response
    {
        if (!$this->isCsrfTokenValid('trigger_age21', $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_settings');
        }

        $academies      = $this->em->getRepository(Academy::class)->findAll();
        $processedCount = 0;
        $deletedCount   = 0;

        foreach ($academies as $academy) {
            $playersBefore = $academy->getPlayers()->count();
            $economicService->checkAgeOutPlayers($academy, $academy->getLastSyncedWeek(), new \DateTimeImmutable());
            $deletedCount  += max(0, $playersBefore - $academy->getPlayers()->count());
            $processedCount++;
        }

        $this->addFlash('success', "Age-21 check run across {$processedCount} academies — {$deletedCount} player(s) removed.");
        return $this->redirectToRoute('admin_settings');
    }

    #[Route('/admin/developer-tools/cleanup-entities', name: 'admin_cleanup_entities', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function cleanupEntities(Request $request, KernelInterface $kernel): Response
    {
        if (!$this->isCsrfTokenValid('cleanup_entities', $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_settings');
        }

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input  = new ArrayInput(['command' => 'app:cleanup:assigned-entities']);
        $output = new BufferedOutput();
        $application->run($input, $output);

        $this->addFlash('success', 'Cleanup command executed.');
        $this->addFlash('info', nl2br(htmlspecialchars(trim($output->fetch()))));

        return $this->redirectToRoute('admin_settings');
    }

    #[Route('/admin/developer-tools/reset-database', name: 'admin_reset_database', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function resetDatabase(): Response
    {
        $this->addFlash('warning', 'Database reset must be run from the CLI:');
        $this->addFlash('info', 'bash scripts/reset_and_seed.sh');
        return $this->redirectToRoute('admin_settings');
    }

    // ── EasyAdmin configuration ───────────────────────────────────────────

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<img src="/images/logo.webp" alt="Wunderkind Factory" style="width:48px;height:48px;image-rendering:pixelated;vertical-align:middle;margin-right:8px;"> Wunderkind')
            ->setFaviconPath('images/logo.webp')
            ->renderContentMaximized();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::section('Users & Academies');
        yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fa fa-users');
        yield MenuItem::linkTo(AcademyCrudController::class, 'Academies', 'fa fa-school');
        yield MenuItem::linkTo(AdminCrudController::class, 'Admins', 'fa fa-user-shield');
        yield MenuItem::section('Sync & Leaderboards');
        yield MenuItem::linkTo(SyncRecordCrudController::class, 'Sync Records', 'fa fa-rotate');
        yield MenuItem::linkTo(LeaderboardEntryCrudController::class, 'Leaderboard Entries', 'fa fa-trophy');
        yield MenuItem::section('Roster');
        yield MenuItem::linkTo(PlayerCrudController::class, 'Players', 'fa fa-person-running');
        yield MenuItem::linkTo(StaffCrudController::class, 'Staff', 'fa fa-chalkboard-user');
        yield MenuItem::linkTo(AgentCrudController::class, 'Agents', 'fa fa-handshake');
        yield MenuItem::linkTo(GuardianCrudController::class, 'Guardians', 'fa fa-users');
        yield MenuItem::section('Narrative');
        yield MenuItem::linkTo(GameEventTemplateCrudController::class, 'Event Templates', 'fa fa-scroll');
        yield MenuItem::linkTo(PlayerArchetypeCrudController::class, 'Player Archetypes', 'fa fa-masks-theater');
        yield MenuItem::section('Configuration');
        yield MenuItem::linkTo(StarterConfigCrudController::class, 'Starter Config', 'fa fa-flag');
        yield MenuItem::linkTo(GameConfigCrudController::class, 'Game Config', 'fa fa-sliders');
        yield MenuItem::section('System');
        yield MenuItem::linkToRoute('Settings & Tools', 'fa fa-gear', 'admin_settings');
        yield MenuItem::section('Market');
        yield MenuItem::linkTo(ScoutCrudController::class, 'Scouts', 'fa fa-binoculars');
        yield MenuItem::linkTo(InvestorCrudController::class, 'Investors', 'fa fa-chart-line');
        yield MenuItem::linkTo(SponsorCrudController::class, 'Sponsors', 'fa fa-star');
    }
}
