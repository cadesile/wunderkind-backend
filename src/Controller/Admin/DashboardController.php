<?php

namespace App\Controller\Admin;

use App\Entity\Club;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Repository\GameConfigRepository;
use App\Repository\NpcClubRepository;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\StarterConfigRepository;
use App\Service\ConfigImportExportService;
use App\Service\EconomicService;
use App\Service\LeagueImportExportService;
use App\Service\MarketPoolService;
use App\Service\NarrativeImportExportService;
use App\Controller\Admin\LeagueCrudController;
use App\Enum\ReputationTier;
use App\Repository\LeagueRepository;
use App\Service\LeagueService;
use App\Service\NpcClubGenerationService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        private NpcClubGenerationService $npcClubGenerationService,
        private NpcClubRepository $npcClubRepository,
        private LeagueRepository            $leagueRepository,
        private LeagueService               $leagueService,
    ) {}

    // ── Dashboard ─────────────────────────────────────────────────────────

    public function index(): Response
    {
        $conn = $this->em->getConnection();

        $byNationality = $conn->fetchAllAssociative(
            'SELECT nationality, COUNT(*) AS cnt FROM player WHERE club_id IS NULL GROUP BY nationality ORDER BY cnt DESC LIMIT 15'
        );
        $byPosition = $conn->fetchAllAssociative(
            'SELECT position, COUNT(*) AS cnt FROM player WHERE club_id IS NULL GROUP BY position ORDER BY cnt DESC'
        );
        $byAge = $conn->fetchAllAssociative(
            'SELECT (EXTRACT(YEAR FROM CURRENT_DATE) - EXTRACT(YEAR FROM date_of_birth)) AS age, COUNT(*) AS cnt FROM player WHERE club_id IS NULL GROUP BY age ORDER BY age'
        );

        return $this->render('admin/dashboard.html.twig', [
            'stats' => [
                'users'           => $this->em->getRepository(User::class)->count([]),
                'academies'       => $this->em->getRepository(Club::class)->count([]),
                'syncs'           => $this->em->getRepository(SyncRecord::class)->count([]),
                'invalidSyncs'    => $this->em->getRepository(SyncRecord::class)->count(['isValid' => false]),
                'poolPlayers'     => (int) $conn->fetchOne('SELECT COUNT(*) FROM player WHERE club_id IS NULL'),
                'assignedPlayers' => (int) $conn->fetchOne('SELECT COUNT(*) FROM player WHERE club_id IS NOT NULL'),
                'poolStaff'       => (int) $conn->fetchOne('SELECT COUNT(*) FROM staff WHERE club_id IS NULL'),
                'assignedStaff'   => (int) $conn->fetchOne('SELECT COUNT(*) FROM staff WHERE club_id IS NOT NULL'),
            ],
            'charts' => [
                'byNationality' => $byNationality,
                'byPosition'    => $byPosition,
                'byAge'         => $byAge,
            ],
        ]);
    }

    // ── App Links ─────────────────────────────────────────────────────────

    #[Route('/admin/app-links', name: 'admin_app_links')]
    #[IsGranted('ROLE_ADMIN')]
    public function appLinks(): Response
    {
        return $this->render('admin/app_links.html.twig', [
            'config' => $this->gameConfigRepository->getConfig(),
        ]);
    }

    #[Route('/admin/app-links/save', name: 'admin_app_links_save', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function saveAppLinks(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('save_app_links', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_app_links']));
        }

        $config = $this->gameConfigRepository->getConfig();
        $config->setAndroidDownloadUrl($request->request->get('androidDownloadUrl', ''));
        $config->setIosDownloadUrl($request->request->get('iosDownloadUrl', ''));
        $this->em->flush();

        $this->addFlash('success', 'App links saved.');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_app_links']));
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
        $leagues = $this->leagueRepository->findAll();

        // Group leagues by country, keyed by tier, for the ability ranges card
        $leaguesByCountry = [];
        foreach ($leagues as $league) {
            $country = $league->getCountry();
            $leaguesByCountry[$country][$league->getTier()] = $league;
        }
        ksort($leaguesByCountry);
        foreach ($leaguesByCountry as &$tiers) {
            ksort($tiers);
        }

        return $this->render('admin/game_config.html.twig', [
            'config'           => $this->gameConfigRepository->getConfig(),
            'leaguesByCountry' => $leaguesByCountry,
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
        $config->setReputationDeltaBase((float) $request->request->get('reputationDeltaBase', 0.15));
        $config->setReputationDeltaFacilityMultiplier((float) $request->request->get('reputationDeltaFacilityMultiplier', 0.15));
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
        $config->setPlayerWageMultiplier((float) $request->request->get('playerWageMultiplier', 1.0));
        $config->setDefaultMoraleMin((int) $request->request->get('defaultMoraleMin', 50));
        $config->setDefaultMoraleMax((int) $request->request->get('defaultMoraleMax', 80));
        $config->setIncidentLowProfessionalismThreshold((int) $request->request->get('incidentLowProfessionalismThreshold', 6));
        $config->setIncidentLowProfessionalismChance((float) $request->request->get('incidentLowProfessionalismChance', 0.3));
        $config->setIncidentHighDeterminationThreshold((int) $request->request->get('incidentHighDeterminationThreshold', 15));
        $config->setIncidentHighDeterminationChance((float) $request->request->get('incidentHighDeterminationChance', 0.25));
        $config->setIncidentAltercationBaseChance((float) $request->request->get('incidentAltercationBaseChance', 0.10));
        $config->setIncidentAltercationSeriousBase((float) $request->request->get('incidentAltercationSeriousBase', 0.2));
        $config->setIncidentAltercationSeriousTemperamentScale((float) $request->request->get('incidentAltercationSeriousTemperamentScale', 0.5));

        $config->setGuardianConvinceMoraleBoost((int) $request->request->get('guardianConvinceMoraleBoost', 5));
        $config->setGuardianConvinceGuardianLoyaltyBoost((int) $request->request->get('guardianConvinceGuardianLoyaltyBoost', 8));
        $config->setGuardianConvinceGuardianDemandIncrease((int) $request->request->get('guardianConvinceGuardianDemandIncrease', 1));
        $config->setGuardianIgnoreMoralePenalty((int) $request->request->get('guardianIgnoreMoralePenalty', 8));
        $config->setGuardianIgnoreLoyaltyTraitPenalty((int) $request->request->get('guardianIgnoreLoyaltyTraitPenalty', 3));
        $config->setGuardianIgnoreGuardianLoyaltyPenalty((int) $request->request->get('guardianIgnoreGuardianLoyaltyPenalty', 12));
        $config->setGuardianIgnoreGuardianDemandIncrease((int) $request->request->get('guardianIgnoreGuardianDemandIncrease', 2));
        $config->setGuardianIgnoreSiblingMoralePenalty((int) $request->request->get('guardianIgnoreSiblingMoralePenalty', 5));
        $config->setGuardianIgnoreSiblingLoyaltyTraitPenalty((int) $request->request->get('guardianIgnoreSiblingLoyaltyTraitPenalty', 2));
        $config->setNonMatchFacilityIncomePercent((int) $request->request->get('nonMatchFacilityIncomePercent', 0));

        // Playing style influence
        $validAttributes = ['pace', 'technical', 'vision', 'power', 'stamina', 'heart'];
        $rawInfluence    = $request->request->all()['playingStyleInfluence'] ?? [];
        $influence       = [];
        foreach (['POSSESSION', 'DIRECT', 'COUNTER', 'HIGH_PRESS'] as $style) {
            $influence[$style] = array_values(
                array_intersect((array) ($rawInfluence[$style] ?? []), $validAttributes)
            );
        }
        $config->setPlayingStyleInfluence($influence);

        $config->setRetirementMinAge((int) $request->request->get('retirementMinAge', 30));
        $config->setRetirementMaxAge((int) $request->request->get('retirementMaxAge', 38));
        $config->setRetirementChance((float) $request->request->get('retirementChance', 0.35));
        $config->setDebugLoggingEnabled($request->request->has('debugLoggingEnabled'));

        // Manager sacking
        $config->setManagerSackingWinRatioTrigger((float) $request->request->get('managerSackingWinRatioTrigger', 0.20));
        $config->setManagerSackingWinRatioRecovery((float) $request->request->get('managerSackingWinRatioRecovery', 0.25));
        $config->setManagerSackingMinGames((int) $request->request->get('managerSackingMinGames', 30));
        $config->setManagerSackingAttendancePenaltyPerWeek((float) $request->request->get('managerSackingAttendancePenaltyPerWeek', 0.05));

        // Inbox / notification frequencies
        $config->setFacilityMaintenanceFrequencyWeeks((int) $request->request->get('facilityMaintenanceFrequencyWeeks', 4));
        $config->setSystemNotificationFrequencyWeeks((int) $request->request->get('systemNotificationFrequencyWeeks', 8));

        // League finances — form submits pounds; multiply by 100 to store pence
        $config->setSmallSponsorMin((int) $request->request->get('smallSponsorMin', 0) * 100);
        $config->setSmallSponsorMax((int) $request->request->get('smallSponsorMax', 0) * 100);
        $config->setMediumSponsorMin((int) $request->request->get('mediumSponsorMin', 0) * 100);
        $config->setMediumSponsorMax((int) $request->request->get('mediumSponsorMax', 0) * 100);
        $config->setLargeSponsorMin((int) $request->request->get('largeSponsorMin', 0) * 100);
        $config->setLargeSponsorMax((int) $request->request->get('largeSponsorMax', 0) * 100);
        $config->setLeaguePositionDecreasePercent((int) $request->request->get('leaguePositionDecreasePercent', 5));

        // Sponsor & investor offer probabilities
        $config->setSponsorProbabilityLocal((float) $request->request->get('sponsorProbabilityLocal', 0.30));
        $config->setSponsorProbabilityRegional((float) $request->request->get('sponsorProbabilityRegional', 0.20));
        $config->setSponsorProbabilityNational((float) $request->request->get('sponsorProbabilityNational', 0.10));
        $config->setSponsorProbabilityElite((float) $request->request->get('sponsorProbabilityElite', 0.05));
        $config->setInvestorProbabilityLocal((float) $request->request->get('investorProbabilityLocal', 0.20));
        $config->setInvestorProbabilityRegional((float) $request->request->get('investorProbabilityRegional', 0.12));
        $config->setInvestorProbabilityNational((float) $request->request->get('investorProbabilityNational', 0.06));
        $config->setInvestorProbabilityElite((float) $request->request->get('investorProbabilityElite', 0.02));

        // Staff limits per club
        $config->setMaxCoachesPerClub((int) $request->request->get('maxCoachesPerClub', 15));
        $config->setMaxManagersPerClub((int) $request->request->get('maxManagersPerClub', 1));
        $config->setMaxDirectorsOfFootballPerClub((int) $request->request->get('maxDirectorsOfFootballPerClub', 1));
        $config->setMaxFacilityManagersPerClub((int) $request->request->get('maxFacilityManagersPerClub', 1));
        $config->setMaxChairmensPerClub((int) $request->request->get('maxChairmensPerClub', 1));
        $config->setMaxScoutsPerClub((int) $request->request->get('maxScoutsPerClub', 3));

        // Attendance ranges — clamp min to [0, max] before persisting
        foreach (['Local', 'Regional', 'National', 'Elite'] as $tier) {
            $minKey = 'attendance' . $tier . 'Min';
            $maxKey = 'attendance' . $tier . 'Max';
            $min = (int) $request->request->get($minKey, 0);
            $max = (int) $request->request->get($maxKey, 100);
            $max = max(0, min(100, $max));
            $min = max(0, min($max, $min)); // min cannot exceed max
            $config->{'set' . $minKey}($min);
            $config->{'set' . $maxKey}($max);
        }

        // Squad configuration
        $config->setSquadSizeMin((int) $request->request->get('squadSizeMin', 11));
        $config->setSquadSizeMax((int) $request->request->get('squadSizeMax', 25));

        // League player ability ranges
        // Submitted as abilityRange[EN][1][min]=55 &abilityRange[EN][1][max]=95 etc.
        $rawRanges    = $request->request->all()['abilityRange'] ?? [];
        $abilityRanges = [];
        foreach ($rawRanges as $country => $tiers) {
            $countryLeagues = [];
            foreach ($tiers as $tier => $bounds) {
                $min = max(0, min(100, (int) ($bounds['min'] ?? 0)));
                $max = max(0, min(100, (int) ($bounds['max'] ?? 100)));
                $min = min($min, $max); // min cannot exceed max
                $countryLeagues[] = ['tier' => (int) $tier, 'min' => $min, 'max' => $max];
            }
            usort($countryLeagues, fn($a, $b) => $a['tier'] <=> $b['tier']);
            $abilityRanges[] = ['country' => (string) $country, 'leagues' => $countryLeagues];
        }
        usort($abilityRanges, fn($a, $b) => strcmp($a['country'], $b['country']));
        $config->setLeaguePlayerAbilityRanges($abilityRanges);

        $this->em->flush();

        $this->addFlash('success', 'Game config saved.');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_game_config']));
    }

    // ── Starter Config ────────────────────────────────────────────────────

    #[Route('/admin/starter-config', name: 'admin_starter_config')]
    #[IsGranted('ROLE_ADMIN')]
    public function starterConfig(): Response
    {
        $leagues = $this->leagueRepository->findAll();
        $dynamicLeagueTiers = [];
        foreach ($leagues as $league) {
            $dynamicLeagueTiers[$league->getCountry()][] = $league->getTier();
        }
        // Ensure tiers are unique and sorted
        foreach ($dynamicLeagueTiers as &$tiers) {
            $tiers = array_unique($tiers);
            sort($tiers);
        }

        return $this->render('admin/starter_config.html.twig', [
            'config' => $this->starterConfigRepository->getConfig(),
            'dynamicLeagueTiers' => $dynamicLeagueTiers,
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
        // Form submits pounds; multiply by 100 to store pence
        $config->setStartingBalance((int) $request->request->get('startingBalance', 50_000) * 100);
        $config->setStarterPlayerCount((int) $request->request->get('starterPlayerCount', 5));
        $config->setStarterCoachCount((int) $request->request->get('starterCoachCount', 1));
        $config->setStarterScoutCount((int) $request->request->get('starterScoutCount', 1));
        $config->setStarterManagerCount((int) $request->request->get('starterManagerCount', 1));
        $config->setStarterDirectorOfFootballCount((int) $request->request->get('starterDirectorOfFootballCount', 0));
        $config->setStarterFacilityManagerCount((int) $request->request->get('starterFacilityManagerCount', 0));
        $config->setStarterChairmanCount((int) $request->request->get('starterChairmanCount', 1));
        $config->setStarterSponsorTier($request->request->get('starterSponsorTier', 'SMALL'));
        $config->setStarterClubTier($request->request->get('starterClubTier', 'local'));

        $facilitiesRaw = trim((string) $request->request->get('defaultFacilities', '{}'));
        if (json_decode($facilitiesRaw) === null && $facilitiesRaw !== 'null') {
            $this->addFlash('danger', 'Default Facilities contains invalid JSON and was not saved. Please fix it and try again.');
        } else {
            $config->setDefaultFacilitiesJson($facilitiesRaw);
        }

        $npcConfigInput = $request->request->all('npcConfig') ?: [];
        $npcSquadConfig = [];
        foreach ($npcConfigInput as $tier => $fields) {
            $npcSquadConfig[$tier] = [
                'playerMin'      => (int) ($fields['playerMin'] ?? 15),
                'playerMax'      => (int) ($fields['playerMax'] ?? 25),
                'managerCount'   => (int) ($fields['managerCount'] ?? 1),
                'coachCount'     => (int) ($fields['coachCount'] ?? 1),
                'chairmanCount'  => (int) ($fields['chairmanCount'] ?? 1),
                'foreignPercent' => (int) ($fields['foreignPercent'] ?? 0),
            ];
            // Ensure min <= max
            if ($npcSquadConfig[$tier]['playerMin'] > $npcSquadConfig[$tier]['playerMax']) {
                $npcSquadConfig[$tier]['playerMax'] = $npcSquadConfig[$tier]['playerMin'];
            }
        }
        if (!empty($npcSquadConfig)) {
            $config->setNpcSquadConfig($npcSquadConfig);
        }

        $reputationTierValue = $request->request->get('starterReputationTier', 'local');
        $reputationTier      = ReputationTier::tryFrom($reputationTierValue) ?? ReputationTier::LOCAL;
        $config->setStarterReputationTier($reputationTier);

        $enabledCountries = $request->request->all('enabledCountries') ?: ['EN'];
        $config->setEnabledCountries($enabledCountries);

        $ranges = $request->request->all('leagueRanges') ?: [];
        // Basic validation: ensure values are numeric
        foreach ($ranges as $country => &$countryTiers) {
            foreach ($countryTiers as $tier => &$bounds) {
                $bounds['min'] = (int) ($bounds['min'] ?? 1);
                $bounds['max'] = (int) ($bounds['max'] ?? 100);
            }
        }
        $config->setLeagueAbilityRanges($ranges);

        // Fan base ranges — submitted as fanBaseRanges[1][min]=50000 etc.
        $fanBaseInput = $request->request->all('fanBaseRanges') ?: [];
        $fanBaseRanges = [];
        foreach ($fanBaseInput as $tier => $bounds) {
            $min = max(0, (int) ($bounds['min'] ?? 0));
            $max = max(0, (int) ($bounds['max'] ?? 0));
            $fanBaseRanges[(string) $tier] = ['min' => min($min, $max), 'max' => max($min, $max)];
        }
        if (!empty($fanBaseRanges)) {
            $config->setFanBaseRanges($fanBaseRanges);
        }

        $config->setFanBasePromotionIncrease((float) $request->request->get('fanBasePromotionIncrease', 0.20));
        $config->setFanBaseRelegationDecrease((float) $request->request->get('fanBaseRelegationDecrease', 0.10));

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
            'players'       => (int) $conn->fetchOne("SELECT COUNT(*) FROM player WHERE club_id IS NULL AND recruitment_source = 'youth_intake'"),
            'staffCoach'          => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE club_id IS NULL AND role = 'coach'"),
            'staffManager'        => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE club_id IS NULL AND role = 'manager'"),
            'staffDirector'       => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE club_id IS NULL AND role = 'director_of_football'"),
            'staffFacilityMgr'    => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE club_id IS NULL AND role = 'facility_manager'"),
            'staffChairman'       => (int) $conn->fetchOne("SELECT COUNT(*) FROM staff WHERE club_id IS NULL AND role = 'chairman'"),
            'scouts'        => (int) $conn->fetchOne('SELECT COUNT(*) FROM scout'),
            'sponsors'      => (int) $conn->fetchOne('SELECT COUNT(*) FROM sponsor WHERE club_id IS NULL'),
            'investors'     => (int) $conn->fetchOne('SELECT COUNT(*) FROM investor WHERE club_id IS NULL'),
            'agents'        => (int) $conn->fetchOne('SELECT COUNT(*) FROM agent'),
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
        $config->setManagerPoolTarget((int) $request->request->get('managerPoolTarget', 5));
        $config->setDirectorOfFootballPoolTarget((int) $request->request->get('directorOfFootballPoolTarget', 2));
        $config->setFacilityManagerPoolTarget((int) $request->request->get('facilityManagerPoolTarget', 3));
        $config->setChairmanPoolTarget((int) $request->request->get('chairmanPoolTarget', 2));
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
            $nationality = $request->request->getString('nationality') ?: null;
            $generated = $this->marketPoolService->forceGeneratePool($nationality);
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

        // Delete guardians referencing unassigned players first (FK constraint)
        $conn->executeStatement('DELETE FROM guardian WHERE player_id IN (SELECT id FROM player WHERE club_id IS NULL)');

        $players   = $conn->executeStatement('DELETE FROM player WHERE club_id IS NULL');
        $staff     = $conn->executeStatement('DELETE FROM staff WHERE club_id IS NULL');
        $scouts    = $conn->executeStatement('DELETE FROM scout');
        $investors = $conn->executeStatement('DELETE FROM investor WHERE assigned_at IS NULL');
        $sponsors  = $conn->executeStatement('DELETE FROM sponsor WHERE assigned_at IS NULL');
        $conn->executeStatement('UPDATE player SET agent_id = NULL WHERE agent_id IS NOT NULL');
        $agents    = $conn->executeStatement('DELETE FROM agent');

        $total = $players + $staff + $scouts + $investors + $sponsors + $agents;
        $this->addFlash('success', "Pool cleared — {$total} entities removed ({$players} players, {$staff} staff, {$scouts} scouts, {$investors} investors, {$sponsors} sponsors, {$agents} agents).");

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_pool_config']));
    }

    // ── Narrative Content ─────────────────────────────────────────────────

    #[Route('/admin/narrative/content', name: 'admin_narrative_content')]
    #[IsGranted('ROLE_ADMIN')]
    public function narrativeContent(): Response
    {
        return $this->render('admin/narrative_content.html.twig');
    }

    #[Route('/admin/narrative/export', name: 'admin_narrative_export', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function narrativeExport(NarrativeImportExportService $service): StreamedResponse
    {
        $data     = $service->export();
        $filename = 'wunderkind-narrative-' . date('Y-m-d') . '.json';
        $json     = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $response = new StreamedResponse(function () use ($json) {
            echo $json;
        });

        $disposition = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    #[Route('/admin/narrative/import', name: 'admin_narrative_import', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function narrativeImport(Request $request, NarrativeImportExportService $service): Response
    {
        if (!$this->isCsrfTokenValid('narrative_import', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_narrative_content']));
        }

        $file = $request->files->get('narrative_file');
        if ($file === null || !$file->isValid()) {
            $this->addFlash('danger', 'No valid file uploaded.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_narrative_content']));
        }

        $raw = file_get_contents($file->getPathname());
        if ($raw === false) {
            $this->addFlash('danger', 'Could not read uploaded file.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_narrative_content']));
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->addFlash('danger', 'Invalid JSON — could not parse the uploaded file.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_narrative_content']));
        }

        $clearFirst = $request->request->has('clear_before_import');
        if ($clearFirst) {
            $service->clearAll();
            $this->addFlash('warning', 'Existing narrative data cleared before import.');
        }

        $result = $service->import($data);

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->addFlash('warning', $error);
            }
        }

        $this->addFlash('success', sprintf(
            'Import complete — %d created, %d updated.',
            $result['created'],
            $result['updated'],
        ));

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_narrative_content']));
    }

    // ── Config Import / Export ────────────────────────────────────────────

    #[Route('/admin/config/content', name: 'admin_config_content')]
    #[IsGranted('ROLE_ADMIN')]
    public function configContent(): Response
    {
        return $this->render('admin/config_content.html.twig');
    }

    #[Route('/admin/config/export', name: 'admin_config_export', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function configExport(ConfigImportExportService $service): StreamedResponse
    {
        $data     = $service->export();
        $filename = 'wunderkind-config-' . date('Y-m-d') . '.json';
        $json     = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $response = new StreamedResponse(function () use ($json) {
            echo $json;
        });

        $disposition = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    #[Route('/admin/config/import', name: 'admin_config_import', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function configImport(Request $request, ConfigImportExportService $service): Response
    {
        if (!$this->isCsrfTokenValid('config_import', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_config_content']));
        }

        $file = $request->files->get('config_file');
        if ($file === null || !$file->isValid()) {
            $this->addFlash('danger', 'No valid file uploaded.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_config_content']));
        }

        $raw = file_get_contents($file->getPathname());
        if ($raw === false) {
            $this->addFlash('danger', 'Could not read uploaded file.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_config_content']));
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->addFlash('danger', 'Invalid JSON — could not parse the uploaded file.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_config_content']));
        }

        $result = $service->import($data);

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->addFlash('warning', $error);
            }
        }

        if ($result['applied']) {
            $this->addFlash('success', 'Config imported successfully.');
        }

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_config_content']));
    }

    // ── NPC Clubs ─────────────────────────────────────────────────────────

    #[Route('/admin/npc-clubs/content', name: 'admin_npc_clubs_content')]
    #[IsGranted('ROLE_ADMIN')]
    public function npcClubsContent(): Response
    {
        $config = $this->poolConfigRepository->getConfig();
        return $this->render('admin/npc_clubs_content.html.twig', [
            'config'         => $config,
            'clubCount'      => $this->npcClubRepository->count([]),
            'clubsByCountry' => $this->npcClubRepository->getCountsByCountryAndTier(),
        ]);
    }

    #[Route('/admin/npc-clubs/generate', name: 'admin_npc_clubs_generate', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function generateNpcClubs(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('generate_npc_clubs', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
        }

        $country        = strtoupper(trim($request->request->get('country', '')));
        $tier           = (int) $request->request->get('tier', 4);
        $count          = (int) $request->request->get('count', 8);
        $deleteExisting = (bool) $request->request->get('delete_existing', false);

        if ($country === '' || $tier < 1 || $tier > 8 || $count < 1) {
            $this->addFlash('danger', 'Invalid parameters — country, tier (1–8) and count are required.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
        }

        $clubs  = $this->npcClubGenerationService->generateClubs($count, $tier, $country, $deleteExisting);
        $suffix = $deleteExisting ? ' (existing deleted first)' : '';
        $this->addFlash('success', sprintf('Generated %d clubs for %s — Tier %d%s.', count($clubs), $country, $tier, $suffix));

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
    }

    #[Route('/admin/leagues/generate', name: 'admin_leagues_generate', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function generateLeagues(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('generate_leagues', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
        }

        $country = strtoupper(trim($request->request->get('country', '')));
        if ($country === '') {
            $this->addFlash('danger', 'Country is required.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
        }

        $created  = $this->leagueService->generateLeaguesForCountry($country);
        $skipped  = 8 - count($created);
        $msg      = sprintf('Created %d leagues for %s', count($created), $country);
        if ($skipped > 0) {
            $msg .= sprintf(', %d already existed', $skipped);
        }
        $this->addFlash('success', $msg . '.');

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
    }

    // ── World Data Import / Export ────────────────────────────────────────

    #[Route('/admin/world/content', name: 'admin_world_content')]
    #[IsGranted('ROLE_ADMIN')]
    public function worldContent(): Response
    {
        return $this->render('admin/world_content.html.twig');
    }

    #[Route('/admin/world/export', name: 'admin_world_export', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function worldExport(LeagueImportExportService $service): StreamedResponse
    {
        $data     = $service->export();
        $filename = 'wunderkind-world-' . date('Y-m-d') . '.json';
        $json     = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $response = new StreamedResponse(function () use ($json) {
            echo $json;
        });

        $disposition = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    #[Route('/admin/world/import', name: 'admin_world_import', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function worldImport(Request $request, LeagueImportExportService $service): Response
    {
        if (!$this->isCsrfTokenValid('world_import', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_world_content']));
        }

        $file = $request->files->get('world_file');
        if ($file === null || !$file->isValid()) {
            $this->addFlash('danger', 'No valid file uploaded.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_world_content']));
        }

        $raw = file_get_contents($file->getPathname());
        if ($raw === false) {
            $this->addFlash('danger', 'Could not read uploaded file.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_world_content']));
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->addFlash('danger', 'Invalid JSON — could not parse the uploaded file.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_world_content']));
        }

        $clearFirst = $request->request->has('clear_before_import');
        if ($clearFirst) {
            try {
                $service->clearAll();
                $this->addFlash('warning', 'Existing world data (leagues & clubs) cleared before import.');
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'Failed to clear existing world data: ' . $e->getMessage());
                return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_world_content']));
            }
        }

        $result = $service->import($data);

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->addFlash('warning', $error);
            }
        }

        if ($result['applied']) {
            $this->addFlash('success', 'World data imported successfully.');
        }

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_world_content']));
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

        $academies      = $this->em->getRepository(Club::class)->findAll();
        $processedCount = 0;
        $deletedCount   = 0;

        foreach ($academies as $club) {
            $playersBefore = $club->getPlayers()->count();
            $economicService->checkAgeOutPlayers($club, $club->getLastSyncedWeek(), new \DateTimeImmutable());
            $deletedCount  += max(0, $playersBefore - $club->getPlayers()->count());
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

    #[Route('/admin/developer-tools/nuclear-reset', name: 'admin_nuclear_reset', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function nuclearReset(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('nuclear_reset', $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_settings']));
        }

        $conn = $this->em->getConnection();

        // Delete in FK-safe order. Settings tables (game_config, starter_config,
        // pool_config, game_event_template, player_archetype) and the admin table
        // are intentionally left untouched.
        $conn->executeStatement('DELETE FROM guardian');
        $conn->executeStatement('DELETE FROM transfer');
        $conn->executeStatement('DELETE FROM inbox_message');
        $conn->executeStatement('DELETE FROM sync_record');
        $conn->executeStatement('DELETE FROM leaderboard_entry');
        $conn->executeStatement('UPDATE player SET agent_id = NULL');
        $conn->executeStatement('DELETE FROM player');
        $conn->executeStatement('DELETE FROM staff');
        $conn->executeStatement('DELETE FROM scout');
        $conn->executeStatement('DELETE FROM agent');
        $conn->executeStatement('DELETE FROM investor');
        $conn->executeStatement('DELETE FROM sponsor');
        // facility table removed — facility_template is config, intentionally preserved
        $conn->executeStatement('DELETE FROM club');
        $conn->executeStatement('DELETE FROM refresh_tokens');
        $conn->executeStatement('DELETE FROM "user"');

        $this->addFlash('success', 'Nuclear reset complete — all player, club, sync, and leaderboard data has been wiped. Settings and admin accounts are untouched.');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_settings']));
    }

    // ── Logs ──────────────────────────────────────────────────────────────

    #[Route('/admin/logs', name: 'admin_logs')]
    #[IsGranted('ROLE_ADMIN')]
    public function logs(Request $request, KernelInterface $kernel): Response
    {
        $logDir = $kernel->getProjectDir() . '/var/log';

        // Discover available log files
        $files = [];
        if (is_dir($logDir)) {
            foreach (glob($logDir . '/*.log') ?: [] as $path) {
                $files[] = basename($path, '.log');
            }
            sort($files);
        }

        $selectedFile = $request->query->get('file', $files[0] ?? 'dev');
        $lines        = max(50, min(2000, (int) ($request->query->get('lines', 500))));

        // Sanitise: only allow filenames that actually exist in the log dir
        if (!in_array($selectedFile, $files, true)) {
            $selectedFile = $files[0] ?? null;
        }

        $logLines = [];
        $error    = null;
        $fileSize = null;

        if ($selectedFile === null) {
            $error = 'No log files found. The application may not have written any logs yet.';
        } else {
            $logPath = $logDir . '/' . $selectedFile . '.log';
            if (!is_readable($logPath)) {
                $error = "Cannot read '{$selectedFile}.log' — check file permissions.";
            } else {
                $fileSize = filesize($logPath);
                $all      = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $logLines = array_slice($all, -$lines);
            }
        }

        return $this->render('admin/logs.html.twig', [
            'files'        => $files,
            'selectedFile' => $selectedFile,
            'lines'        => $lines,
            'logLines'     => $logLines,
            'fileSize'     => $fileSize,
            'error'        => $error,
        ]);
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
        yield MenuItem::linkTo(ClubCrudController::class, 'Academies', 'fa fa-school');
        yield MenuItem::linkTo(AdminCrudController::class, 'Admins', 'fa fa-user-shield');
        yield MenuItem::section('Sync & Leaderboards');
        yield MenuItem::linkTo(SyncRecordCrudController::class, 'Sync Records', 'fa fa-rotate');
        yield MenuItem::linkTo(LeaderboardEntryCrudController::class, 'Leaderboard Entries', 'fa fa-trophy');
        yield MenuItem::section('Roster');
        yield MenuItem::linkTo(PlayerCrudController::class, 'Players', 'fa fa-person-running');
        yield MenuItem::linkTo(StaffCrudController::class, 'Staff', 'fa fa-users');
        yield MenuItem::linkTo(ScoutCrudController::class, 'Scouts', 'fa fa-binoculars');
        yield MenuItem::linkTo(AgentCrudController::class, 'Agents', 'fa fa-handshake');
        yield MenuItem::linkTo(GuardianCrudController::class, 'Guardians', 'fa fa-users');
        yield MenuItem::section('Narrative');
        yield MenuItem::linkTo(GameEventTemplateCrudController::class, 'Event Templates', 'fa fa-scroll');
        yield MenuItem::linkTo(FacilityTemplateCrudController::class, 'Facility Templates', 'fa fa-building');
        yield MenuItem::linkTo(PlayerArchetypeCrudController::class, 'Player Archetypes', 'fa fa-masks-theater');
        yield MenuItem::linkToRoute('Import / Export', 'fa fa-file-arrow-up', 'admin_narrative_content');
        yield MenuItem::section('Configuration');
        yield MenuItem::linkToRoute('Starter Config', 'fa fa-flag', 'admin_starter_config');
        yield MenuItem::linkToRoute('Game Config', 'fa fa-sliders', 'admin_game_config');
        yield MenuItem::linkToRoute('Pool Config', 'fa fa-layer-group', 'admin_pool_config');
        yield MenuItem::linkToRoute('Import / Export', 'fa fa-file-arrow-up', 'admin_config_content');
        yield MenuItem::section('System');
        yield MenuItem::linkToRoute('App Links', 'fa fa-mobile-screen', 'admin_app_links');
        yield MenuItem::linkToRoute('Settings & Tools', 'fa fa-gear', 'admin_settings');
        yield MenuItem::linkToRoute('Logs', 'fa fa-file-lines', 'admin_logs');
        yield MenuItem::section('Market');
        yield MenuItem::linkTo(InvestorCrudController::class, 'Investors', 'fa fa-chart-line');
        yield MenuItem::linkTo(SponsorCrudController::class, 'Sponsors', 'fa fa-star');
        yield MenuItem::section('Clubs & Leagues');
        yield MenuItem::linkTo(NpcClubCrudController::class, 'NPC Clubs', 'fa fa-shield-halved');
        yield MenuItem::linkTo(LeagueCrudController::class, 'Leagues', 'fa fa-trophy');
        yield MenuItem::linkTo(TacticalAdvantageCrudController::class, 'Tactical Matrix', 'fa fa-chess-board');
        yield MenuItem::linkToRoute('Import / Export', 'fa fa-file-arrow-up', 'admin_world_content');
        yield MenuItem::linkToRoute('Generate', 'fa fa-wand-magic-sparkles', 'admin_npc_clubs_content');
    }
}
