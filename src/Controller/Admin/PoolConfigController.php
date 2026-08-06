<?php

namespace App\Controller\Admin;

use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;
use App\Repository\PoolConfigRepository;
use App\Service\MarketPoolService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PoolConfigController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PoolConfigRepository $poolConfigRepository,
        private readonly MarketPoolService $marketPoolService,
    ) {}

    // ── Player Pool ──────────────────────────────────────────────────────

    #[Route('/admin/player-pool-config', name: 'admin_player_pool_config')]
    #[IsGranted('ROLE_ADMIN')]
    public function playerPoolConfig(): Response
    {
        return $this->render('admin/pool_player_config.html.twig', [
            'config'     => $this->poolConfigRepository->getConfig(),
            'poolCounts' => $this->playerCounts(),
        ]);
    }

    #[Route('/admin/player-pool-config/save', name: 'admin_player_pool_config_save', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function savePlayerPoolConfig(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('save_player_pool_config', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_player_pool_config']));
        }

        $config = $this->poolConfigRepository->getConfig();

        // Player generation
        $config->setPlayerAgeMin((int) $request->request->get('playerAgeMin', 12));
        $config->setPlayerAgeMax((int) $request->request->get('playerAgeMax', 13));
        $config->setPlayerPotentialMin((int) $request->request->get('playerPotentialMin', 40));
        $config->setPlayerPotentialMax((int) $request->request->get('playerPotentialMax', 80));
        $config->setPlayerPotentialMean((int) $request->request->get('playerPotentialMean', 60));
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

        // Pool target
        $config->setPlayerPoolTarget((int) $request->request->get('playerPoolTarget', 50));

        $this->em->flush();

        $this->addFlash('success', 'Player pool config saved.');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_player_pool_config']));
    }

    #[Route('/admin/player-pool-config/generate-chunk', name: 'admin_player_pool_generate_chunk', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function generatePlayerPoolChunk(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('generate_player_pool', $request->request->get('_token'))) {
            return $this->json(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
        }

        $type        = $request->request->get('type', '');
        $nationality = $request->request->getString('nationality') ?: null;
        $mode        = $request->request->get('mode', 'force');
        $cfg         = $this->poolConfigRepository->getConfig();
        $conn        = $this->em->getConnection();

        $target = match ($type) {
            'players' => $cfg->getPlayerPoolTarget(),
            default   => 0,
        };

        $count = $target;
        if ($mode === 'replenish') {
            $current = match ($type) {
                'players' => (int) $conn->fetchOne("SELECT COUNT(*) FROM player WHERE recruitment_source = 'youth_intake'"),
                default   => 0,
            };
            $count = max(0, $target - $current);
        }

        if ($count <= 0) {
            return $this->json(['success' => true, 'count' => 0, 'message' => 'Already at target.', 'skipped' => true]);
        }

        try {
            match ($type) {
                'players' => $this->marketPoolService->generatePlayers($count, RecruitmentSource::YOUTH_INTAKE, $nationality),
                default   => throw new \InvalidArgumentException("Unknown pool type: {$type}"),
            };
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return $this->json(['success' => true, 'count' => $count, 'message' => "{$count} generated."]);
    }

    #[Route('/admin/player-pool-config/counts', name: 'admin_player_pool_counts', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function playerPoolCountsJson(): JsonResponse
    {
        return $this->json($this->playerCounts());
    }

    #[Route('/admin/player-pool-config/clear', name: 'admin_player_pool_clear', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function clearPlayerPool(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('clear_player_pool', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_player_pool_config']));
        }

        $conn = $this->em->getConnection();

        // Delete guardians referencing players first (FK constraint)
        $conn->executeStatement('DELETE FROM guardian WHERE player_id IN (SELECT id FROM player)');
        $players = $conn->executeStatement('DELETE FROM player');

        $this->addFlash('success', "Player pool cleared — {$players} players removed.");
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_player_pool_config']));
    }

    /** @return array{players: int} */
    private function playerCounts(): array
    {
        $conn = $this->em->getConnection();
        return [
            'players' => (int) $conn->fetchOne("SELECT COUNT(*) FROM player WHERE recruitment_source = 'youth_intake'"),
        ];
    }

    // ── Staff Pool (roles + scouts) ──────────────────────────────────────

    #[Route('/admin/staff-pool-config', name: 'admin_staff_pool_config')]
    #[IsGranted('ROLE_ADMIN')]
    public function staffPoolConfig(): Response
    {
        return $this->render('admin/pool_staff_config.html.twig', [
            'config'     => $this->poolConfigRepository->getConfig(),
            'poolCounts' => $this->staffCounts(),
        ]);
    }

    #[Route('/admin/staff-pool-config/save', name: 'admin_staff_pool_config_save', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function saveStaffPoolConfig(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('save_staff_pool_config', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_staff_pool_config']));
        }

        $config = $this->poolConfigRepository->getConfig();

        // Coach generation (ranges apply to all 5 staff roles)
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

        // Pool targets
        $config->setCoachPoolTarget((int) $request->request->get('coachPoolTarget', 10));
        $config->setManagerPoolTarget((int) $request->request->get('managerPoolTarget', 5));
        $config->setDirectorOfFootballPoolTarget((int) $request->request->get('directorOfFootballPoolTarget', 2));
        $config->setFacilityManagerPoolTarget((int) $request->request->get('facilityManagerPoolTarget', 3));
        $config->setChairmanPoolTarget((int) $request->request->get('chairmanPoolTarget', 2));
        $config->setScoutPoolTarget((int) $request->request->get('scoutPoolTarget', 5));

        $this->em->flush();

        $this->addFlash('success', 'Staff pool config saved.');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_staff_pool_config']));
    }

    #[Route('/admin/staff-pool-config/generate-chunk', name: 'admin_staff_pool_generate_chunk', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function generateStaffPoolChunk(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('generate_staff_pool', $request->request->get('_token'))) {
            return $this->json(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
        }

        $type        = $request->request->get('type', '');
        $nationality = $request->request->getString('nationality') ?: null;
        $mode        = $request->request->get('mode', 'force');
        $cfg         = $this->poolConfigRepository->getConfig();
        $conn        = $this->em->getConnection();

        $target = match ($type) {
            'coaches'           => $cfg->getCoachPoolTarget(),
            'managers'          => $cfg->getManagerPoolTarget(),
            'directors'         => $cfg->getDirectorOfFootballPoolTarget(),
            'facility_managers' => $cfg->getFacilityManagerPoolTarget(),
            'chairmen'          => $cfg->getChairmanPoolTarget(),
            'scouts'            => $cfg->getScoutPoolTarget(),
            default             => 0,
        };

        $count = $target;
        if ($mode === 'replenish') {
            $current = match ($type) {
                'coaches'           => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'coach'"),
                'managers'          => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'manager'"),
                'directors'         => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'director_of_football'"),
                'facility_managers' => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'facility_manager'"),
                'chairmen'          => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'chairman'"),
                'scouts'            => (int) $conn->fetchOne('SELECT COUNT(*) FROM scout'),
                default             => 0,
            };
            $count = max(0, $target - $current);
        }

        if ($count <= 0) {
            return $this->json(['success' => true, 'count' => 0, 'message' => 'Already at target.', 'skipped' => true]);
        }

        try {
            match ($type) {
                'coaches'           => $this->marketPoolService->generateStaffForRole(StaffRole::COACH, $count, $nationality),
                'managers'          => $this->marketPoolService->generateStaffForRole(StaffRole::MANAGER, $count, $nationality),
                'directors'         => $this->marketPoolService->generateStaffForRole(StaffRole::DIRECTOR_OF_FOOTBALL, $count, $nationality),
                'facility_managers' => $this->marketPoolService->generateStaffForRole(StaffRole::FACILITY_MANAGER, $count, $nationality),
                'chairmen'          => $this->marketPoolService->generateStaffForRole(StaffRole::CHAIRMAN, $count, $nationality),
                'scouts'            => $this->marketPoolService->generateScouts($count, $nationality),
                default             => throw new \InvalidArgumentException("Unknown pool type: {$type}"),
            };
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return $this->json(['success' => true, 'count' => $count, 'message' => "{$count} generated."]);
    }

    #[Route('/admin/staff-pool-config/counts', name: 'admin_staff_pool_counts', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function staffPoolCountsJson(): JsonResponse
    {
        return $this->json($this->staffCounts());
    }

    #[Route('/admin/staff-pool-config/clear', name: 'admin_staff_pool_clear', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function clearStaffPool(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('clear_staff_pool', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_staff_pool_config']));
        }

        $conn   = $this->em->getConnection();
        $staff  = $conn->executeStatement('DELETE FROM staff');
        $scouts = $conn->executeStatement('DELETE FROM scout');

        $this->addFlash('success', "Staff pool cleared — {$staff} staff, {$scouts} scouts removed.");
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_staff_pool_config']));
    }

    /** @return array{staffCoach: int, staffManager: int, staffDirector: int, staffFacilityMgr: int, staffChairman: int, scouts: int} */
    private function staffCounts(): array
    {
        $conn = $this->em->getConnection();
        return [
            'staffCoach'       => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'coach'"),
            'staffManager'     => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'manager'"),
            'staffDirector'    => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'director_of_football'"),
            'staffFacilityMgr' => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'facility_manager'"),
            'staffChairman'    => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE role = 'chairman'"),
            'scouts'           => (int) $conn->fetchOne('SELECT COUNT(*) FROM scout'),
        ];
    }

    // ── Investor Pool (agents + sponsors + investors) ───────────────────

    #[Route('/admin/investor-pool-config', name: 'admin_investor_pool_config')]
    #[IsGranted('ROLE_ADMIN')]
    public function investorPoolConfig(): Response
    {
        return $this->render('admin/pool_investor_config.html.twig', [
            'config'     => $this->poolConfigRepository->getConfig(),
            'poolCounts' => $this->investorCounts(),
        ]);
    }

    #[Route('/admin/investor-pool-config/save', name: 'admin_investor_pool_config_save', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function saveInvestorPoolConfig(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('save_investor_pool_config', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_investor_pool_config']));
        }

        $config = $this->poolConfigRepository->getConfig();

        // Agent generation
        $config->setAgentReputationMin((int) $request->request->get('agentReputationMin', 30));
        $config->setAgentReputationMax((int) $request->request->get('agentReputationMax', 70));
        $config->setAgentAgeMin((int) $request->request->get('agentAgeMin', 30));
        $config->setAgentAgeMax((int) $request->request->get('agentAgeMax', 60));

        // Pool targets
        $config->setSponsorPoolTarget((int) $request->request->get('sponsorPoolTarget', 10));
        $config->setInvestorPoolTarget((int) $request->request->get('investorPoolTarget', 5));
        $config->setAgentPoolTarget((int) $request->request->get('agentPoolTarget', 20));

        $this->em->flush();

        $this->addFlash('success', 'Investor pool config saved.');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_investor_pool_config']));
    }

    #[Route('/admin/investor-pool-config/generate-chunk', name: 'admin_investor_pool_generate_chunk', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function generateInvestorPoolChunk(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('generate_investor_pool', $request->request->get('_token'))) {
            return $this->json(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
        }

        $type   = $request->request->get('type', '');
        $mode   = $request->request->get('mode', 'force');
        $cfg    = $this->poolConfigRepository->getConfig();
        $conn   = $this->em->getConnection();

        $target = match ($type) {
            'agents'    => $cfg->getAgentPoolTarget(),
            'sponsors'  => $cfg->getSponsorPoolTarget(),
            'investors' => $cfg->getInvestorPoolTarget(),
            default     => 0,
        };

        $count = $target;
        if ($mode === 'replenish') {
            $current = match ($type) {
                'agents'    => (int) $conn->fetchOne('SELECT COUNT(*) FROM agent'),
                'sponsors'  => (int) $conn->fetchOne('SELECT COUNT(*) FROM sponsor WHERE club_id IS NULL'),
                'investors' => (int) $conn->fetchOne('SELECT COUNT(*) FROM investor WHERE club_id IS NULL'),
                default     => 0,
            };
            $count = max(0, $target - $current);
        }

        if ($count <= 0) {
            return $this->json(['success' => true, 'count' => 0, 'message' => 'Already at target.', 'skipped' => true]);
        }

        try {
            match ($type) {
                'agents'    => $this->marketPoolService->generateAgents($count),
                'sponsors'  => $this->marketPoolService->generateSponsors($count),
                'investors' => $this->marketPoolService->generateInvestors($count),
                default     => throw new \InvalidArgumentException("Unknown pool type: {$type}"),
            };
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return $this->json(['success' => true, 'count' => $count, 'message' => "{$count} generated."]);
    }

    #[Route('/admin/investor-pool-config/counts', name: 'admin_investor_pool_counts', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function investorPoolCountsJson(): JsonResponse
    {
        return $this->json($this->investorCounts());
    }

    #[Route('/admin/investor-pool-config/clear', name: 'admin_investor_pool_clear', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function clearInvestorPool(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('clear_investor_pool', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_investor_pool_config']));
        }

        $conn      = $this->em->getConnection();
        $investors = $conn->executeStatement('DELETE FROM investor WHERE assigned_at IS NULL');
        $sponsors  = $conn->executeStatement('DELETE FROM sponsor WHERE assigned_at IS NULL');
        $agents    = $conn->executeStatement('DELETE FROM agent');

        $this->addFlash('success', "Investor pool cleared — {$investors} investors, {$sponsors} sponsors, {$agents} agents removed.");
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_investor_pool_config']));
    }

    /** @return array{agents: int, sponsors: int, investors: int} */
    private function investorCounts(): array
    {
        $conn = $this->em->getConnection();
        return [
            'agents'    => (int) $conn->fetchOne('SELECT COUNT(*) FROM agent'),
            'sponsors'  => (int) $conn->fetchOne('SELECT COUNT(*) FROM sponsor WHERE club_id IS NULL'),
            'investors' => (int) $conn->fetchOne('SELECT COUNT(*) FROM investor WHERE club_id IS NULL'),
        ];
    }
}
