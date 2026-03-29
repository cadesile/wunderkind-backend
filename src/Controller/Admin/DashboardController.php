<?php

namespace App\Controller\Admin;

use App\Entity\Academy;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Repository\GameConfigRepository;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\StarterConfigRepository;
use App\Service\EconomicService;
use App\Service\MarketPoolService;
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
        private StarterConfigRepository $starterConfigRepository,
        private PoolConfigRepository $poolConfigRepository,
        private MarketPoolService $marketPoolService,
        private PlayerRepository $playerRepository,
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
            'SELECT (EXTRACT(YEAR FROM CURRENT_DATE) - EXTRACT(YEAR FROM date_of_birth)) AS age, COUNT(*) AS cnt FROM player WHERE academy_id IS NULL GROUP BY age ORDER BY age'
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
        return $this->render('admin/settings.html.twig');
    }

    // ── Game Config ───────────────────────────────────────────────────────

    #[Route('/admin/game-config', name: 'admin_game_config')]
    #[IsGranted('ROLE_ADMIN')]
    public function gameConfig(): Response
    {
        return $this->render('admin/game_config.html.twig', [
            'config' => $this->gameConfigRepository->getConfig(),
        ]);
    }

    #[Route('/admin/game-config/save', name: 'admin_game_config_save', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function saveGameConfig(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('save_game_config', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_game_config']));
        }

        $config = $this->gameConfigRepository->getConfig();
        $config->setCliqueRelationshipThreshold((int) $request->request->get('cliqueRelationshipThreshold', 20));
        $config->setCliqueSquadCapPercent((int) $request->request->get('cliqueSquadCapPercent', 30));
        $config->setCliqueMinTenureWeeks((int) $request->request->get('cliqueMinTenureWeeks', 3));
        $config->setBaseXP((int) $request->request->get('baseXP', 10));
        $config->setBaseInjuryProbability((float) $request->request->get('baseInjuryProbability', 0.05));
        $config->setRegressionUpperThreshold((int) $request->request->get('regressionUpperThreshold', 14));
        $config->setRegressionLowerThreshold((int) $request->request->get('regressionLowerThreshold', 7));
        $config->setReputationDeltaBase((float) $request->request->get('reputationDeltaBase', 0.5));
        $config->setReputationDeltaFacilityMultiplier((float) $request->request->get('reputationDeltaFacilityMultiplier', 1.2));
        $config->setInjuryMinorWeight((int) $request->request->get('injuryMinorWeight', 60));
        $config->setInjuryModerateWeight((int) $request->request->get('injuryModerateWeight', 30));
        $config->setInjurySeriousWeight((int) $request->request->get('injurySeriousWeight', 10));

        $config->setScoutMoraleThreshold((int) $request->request->get('scoutMoraleThreshold', 40));
        $config->setScoutRevealWeeks((int) $request->request->get('scoutRevealWeeks', 2));
        $config->setScoutAbilityErrorRange((int) $request->request->get('scoutAbilityErrorRange', 30));
        $config->setScoutMaxAssignments((int) $request->request->get('scoutMaxAssignments', 5));
        $config->setMissionGemRollThresholds([
            (float) $request->request->get('gemThreshold0', 0.25),
            (float) $request->request->get('gemThreshold1', 0.75),
            (float) $request->request->get('gemThreshold2', 0.85),
            (float) $request->request->get('gemThreshold3', 0.94),
        ]);
        $config->setPlayerFeeMultiplier((float) $request->request->get('playerFeeMultiplier', 1000.0));
        $config->setDefaultMoraleMin((int) $request->request->get('defaultMoraleMin', 50));
        $config->setDefaultMoraleMax((int) $request->request->get('defaultMoraleMax', 80));
        $this->em->flush();

        $this->addFlash('success', 'Game config saved.');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_game_config']));
    }

    // ── Starter Config ────────────────────────────────────────────────────

    #[Route('/admin/starter-config', name: 'admin_starter_config')]
    #[IsGranted('ROLE_ADMIN')]
    public function starterConfig(): Response
    {
        return $this->render('admin/starter_config.html.twig', [
            'config' => $this->starterConfigRepository->getConfig(),
        ]);
    }

    #[Route('/admin/starter-config/save', name: 'admin_starter_config_save', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function saveStarterConfig(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('save_starter_config', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_starter_config']));
        }

        $config = $this->starterConfigRepository->getConfig();
        $config->setStartingBalance((int) $request->request->get('startingBalance', 5_000_000));
        $config->setStarterPlayerCount((int) $request->request->get('starterPlayerCount', 5));
        $config->setStarterCoachCount((int) $request->request->get('starterCoachCount', 1));
        $config->setStarterScoutCount((int) $request->request->get('starterScoutCount', 1));
        $config->setStarterSponsorTier($request->request->get('starterSponsorTier', 'SMALL'));
        $this->em->persist($config);
        $this->em->flush();

        $this->addFlash('success', 'Starter config saved.');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_starter_config']));
    }

    // ── Pool Config ───────────────────────────────────────────────────────

    #[Route('/admin/pool-config', name: 'admin_pool_config')]
    #[IsGranted('ROLE_ADMIN')]
    public function poolConfig(): Response
    {
        $conn = $this->em->getConnection();

        $poolCounts = [
            'players'   => (int) $conn->fetchOne('SELECT COUNT(*) FROM player WHERE academy_id IS NULL'),
            'coaches'   => (int) $conn->fetchOne('SELECT COUNT(*) FROM staff WHERE academy_id IS NULL'),
            'scouts'    => (int) $conn->fetchOne('SELECT COUNT(*) FROM scout'),
            'sponsors'  => (int) $conn->fetchOne('SELECT COUNT(*) FROM sponsor WHERE academy_id IS NULL'),
            'investors' => (int) $conn->fetchOne('SELECT COUNT(*) FROM investor WHERE academy_id IS NULL'),
            'agents'    => (int) $conn->fetchOne('SELECT COUNT(*) FROM agent'),
        ];

        return $this->render('admin/pool_config.html.twig', [
            'config'     => $this->poolConfigRepository->getConfig(),
            'poolCounts' => $poolCounts,
        ]);
    }

    #[Route('/admin/pool-config/save', name: 'admin_pool_config_save', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function savePoolConfig(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('save_pool_config', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_pool_config']));
        }

        $config = $this->poolConfigRepository->getConfig();

        // Player generation
        $config->setPlayerAgeMin((int) $request->request->get('playerAgeMin', 12));
        $config->setPlayerAgeMax((int) $request->request->get('playerAgeMax', 13));
        $config->setPlayerPotentialMin((int) $request->request->get('playerPotentialMin', 40));
        $config->setPlayerPotentialMax((int) $request->request->get('playerPotentialMax', 80));
        $config->setPlayerPotentialMean((int) $request->request->get('playerPotentialMean', 60));
        $config->setPlayerAbilityMin((int) $request->request->get('playerAbilityMin', 3));
        $config->setPlayerAbilityMax((int) $request->request->get('playerAbilityMax', 10));
        $config->setPlayerAttributeBudgetMin((int) $request->request->get('playerAttributeBudgetMin', 6));
        $config->setPlayerAttributeBudgetMax((int) $request->request->get('playerAttributeBudgetMax', 20));
        $config->setPlayerAgentChancePercent((int) $request->request->get('playerAgentChancePercent', 40));
        $config->setPlayerHeightMin((int) $request->request->get('playerHeightMin', 145));
        $config->setPlayerHeightMax((int) $request->request->get('playerHeightMax', 160));
        $config->setPlayerWeightMin((int) $request->request->get('playerWeightMin', 38));
        $config->setPlayerWeightMax((int) $request->request->get('playerWeightMax', 55));
        $config->setPersonalityTraitMin((int) $request->request->get('personalityTraitMin', 30));
        $config->setPersonalityTraitMax((int) $request->request->get('personalityTraitMax', 70));

        // Position weighting
        $config->setPositionWeightGk((int) $request->request->get('positionWeightGk', 8));
        $config->setPositionWeightDef((int) $request->request->get('positionWeightDef', 30));
        $config->setPositionWeightMid((int) $request->request->get('positionWeightMid', 38));
        $config->setPositionWeightAtt((int) $request->request->get('positionWeightAtt', 24));

        // Coach generation
        $config->setCoachAgeMin((int) $request->request->get('coachAgeMin', 28));
        $config->setCoachAgeMax((int) $request->request->get('coachAgeMax', 60));
        $config->setCoachAbilityMin((int) $request->request->get('coachAbilityMin', 40));
        $config->setCoachAbilityMax((int) $request->request->get('coachAbilityMax', 75));

        // Scout generation
        $config->setScoutAgeMin((int) $request->request->get('scoutAgeMin', 28));
        $config->setScoutAgeMax((int) $request->request->get('scoutAgeMax', 40));
        $config->setScoutExperienceMin((int) $request->request->get('scoutExperienceMin', 0));
        $config->setScoutExperienceMax((int) $request->request->get('scoutExperienceMax', 10));
        $config->setScoutJudgementMin((int) $request->request->get('scoutJudgementMin', 40));
        $config->setScoutJudgementMax((int) $request->request->get('scoutJudgementMax', 80));

        // Agent generation
        $config->setAgentReputationMin((int) $request->request->get('agentReputationMin', 30));
        $config->setAgentReputationMax((int) $request->request->get('agentReputationMax', 70));
        $config->setAgentAgeMin((int) $request->request->get('agentAgeMin', 30));
        $config->setAgentAgeMax((int) $request->request->get('agentAgeMax', 60));

        // Pool targets
        $config->setPlayerPoolTarget((int) $request->request->get('playerPoolTarget', 50));
        $config->setCoachPoolTarget((int) $request->request->get('coachPoolTarget', 10));
        $config->setScoutPoolTarget((int) $request->request->get('scoutPoolTarget', 5));
        $config->setSponsorPoolTarget((int) $request->request->get('sponsorPoolTarget', 10));
        $config->setInvestorPoolTarget((int) $request->request->get('investorPoolTarget', 5));
        $config->setAgentPoolTarget((int) $request->request->get('agentPoolTarget', 20));

        $this->em->flush();

        $this->addFlash('success', 'Pool config saved.');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_pool_config']));
    }

    #[Route('/admin/pool-config/generate', name: 'admin_pool_generate', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function generatePool(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('generate_pool', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_pool_config']));
        }

        $mode = $request->request->get('mode', 'replenish');

        if ($mode === 'force') {
            $generated = $this->marketPoolService->forceGeneratePool();
            $this->addFlash('success', 'Force generated: ' . implode(', ', $generated) . '.');
        } else {
            $generated = $this->marketPoolService->replenishPool();
            if (empty($generated)) {
                $this->addFlash('info', 'All pools are already at or above their targets — nothing generated.');
            } else {
                $this->addFlash('success', 'Replenished: ' . implode(', ', $generated) . '.');
            }
        }

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_pool_config']));
    }

    #[Route('/admin/pool-config/clear', name: 'admin_pool_clear', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function clearPool(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('clear_pool', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_pool_config']));
        }

        $conn = $this->em->getConnection();

        $players   = $conn->executeStatement('DELETE FROM player WHERE academy_id IS NULL');
        $staff     = $conn->executeStatement('DELETE FROM staff WHERE academy_id IS NULL');
        $scouts    = $conn->executeStatement('DELETE FROM scout WHERE assigned_at IS NULL');
        $investors = $conn->executeStatement('DELETE FROM investor WHERE assigned_at IS NULL');
        $sponsors  = $conn->executeStatement('DELETE FROM sponsor WHERE assigned_at IS NULL');
        $agents    = $conn->executeStatement(
            'DELETE FROM agent WHERE id NOT IN (
                SELECT DISTINCT agent_id FROM player
                WHERE academy_id IS NOT NULL AND agent_id IS NOT NULL
            )'
        );

        $total = $players + $staff + $scouts + $investors + $sponsors + $agents;
        $this->addFlash('success', "Pool cleared — {$total} entities removed ({$players} players, {$staff} staff, {$scouts} scouts, {$investors} investors, {$sponsors} sponsors, {$agents} agents).");

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_pool_config']));
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
        yield MenuItem::linkTo(StaffCrudController::class, 'Coaches', 'fa fa-chalkboard-user');
        yield MenuItem::linkTo(ScoutCrudController::class, 'Scouts', 'fa fa-binoculars');
        yield MenuItem::linkTo(AgentCrudController::class, 'Agents', 'fa fa-handshake');
        yield MenuItem::linkTo(GuardianCrudController::class, 'Guardians', 'fa fa-users');
        yield MenuItem::section('Narrative');
        yield MenuItem::linkTo(GameEventTemplateCrudController::class, 'Event Templates', 'fa fa-scroll');
        yield MenuItem::linkTo(PlayerArchetypeCrudController::class, 'Player Archetypes', 'fa fa-masks-theater');
        yield MenuItem::section('Configuration');
        yield MenuItem::linkToRoute('Starter Config', 'fa fa-flag', 'admin_starter_config');
        yield MenuItem::linkToRoute('Game Config', 'fa fa-sliders', 'admin_game_config');
        yield MenuItem::linkToRoute('Pool Config', 'fa fa-layer-group', 'admin_pool_config');
        yield MenuItem::section('System');
        yield MenuItem::linkToRoute('Settings & Tools', 'fa fa-gear', 'admin_settings');
        yield MenuItem::section('Market');
        yield MenuItem::linkTo(InvestorCrudController::class, 'Investors', 'fa fa-chart-line');
        yield MenuItem::linkTo(SponsorCrudController::class, 'Sponsors', 'fa fa-star');
    }
}
