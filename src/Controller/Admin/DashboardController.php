<?php

namespace App\Controller\Admin;

use App\Entity\Club;
use App\Entity\SyncRecord;
use App\Entity\User;
use App\Entity\GameConfig;
use App\Repository\GameConfigRepository;
use App\Repository\NpcClubRepository;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\SocialAccountConnectionRepository;
use App\Repository\SocialPostTemplateRepository;
use App\Repository\StarterConfigRepository;
use App\Repository\LanguageRepository;
use App\Service\Admin\AdminQueueCounts;
use App\Service\Admin\LeagueTierDefaultDiff;
use EasyCorp\Bundle\EasyAdminBundle\Config\Asset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Menu\ControllerMenuItem;
use App\Service\ConfigImportExportService;
use App\Service\LeagueImportExportService;
use App\Service\NarrativeImportExportService;
use App\Service\TranslationCatalogueImportExportService;
use App\Service\TrophyConfigFactory;
use App\Controller\Admin\BetaRequestCrudController;
use App\Controller\Admin\DeletionRequestCrudController;
use App\Controller\Admin\LeagueCrudController;
use App\Controller\Admin\SocialPostTemplateCrudController;
use App\Controller\Admin\LanguageCrudController;
use App\Controller\Admin\TranslationCrudController;
use App\Controller\Admin\TranslationKeyCrudController;
use App\Enum\Appearance\TrophyFinish;
use App\Enum\Appearance\TrophyStyle;
use App\Enum\Country;
use App\Service\SetupTutorialValidator;
use App\Enum\ReputationTier;
use App\Repository\LeagueRepository;
use App\Service\LeagueService;
use App\Repository\CountryWorldPackCacheRepository;
use App\Service\NpcClubGenerationService;
use App\Service\WorldPackCacheService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
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
        private PlayerRepository $playerRepository,
        private NpcClubGenerationService $npcClubGenerationService,
        private NpcClubRepository $npcClubRepository,
        private LeagueRepository                 $leagueRepository,
        private LeagueService                    $leagueService,
        private CountryWorldPackCacheRepository  $worldPackCacheRepository,
        private WorldPackCacheService            $worldPackCacheService,
        private \App\Repository\FacilityTemplateRepository $facilityTemplateRepository,
        private SocialAccountConnectionRepository $socialAccountConnectionRepository,
        private SocialPostTemplateRepository $socialPostTemplateRepository,
        private AdminQueueCounts $adminQueueCounts,
        private \App\Repository\Location\CountryProfileRepository $countryProfileRepository,
    ) {}

    // ── Dashboard ─────────────────────────────────────────────────────────

    #[IsGranted('ROLE_ADMIN')]
    public function index(): Response
    {
        // Only the headline totals are rendered server-side so the shell paints
        // immediately; every heavy panel (growth, leaderboards, pool breakdowns)
        // is fetched by the page from AdminStatsController and cached there.
        return $this->render('admin/dashboard.html.twig', [
            'stats' => [
                'users'        => $this->em->getRepository(User::class)->count([]),
                'clubs'        => $this->em->getRepository(Club::class)->count([]),
                'syncs'        => $this->em->getRepository(SyncRecord::class)->count([]),
                'invalidSyncs' => $this->em->getRepository(SyncRecord::class)->count(['isValid' => false]),
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
        $config->setFacebookPageUrl($request->request->get('facebookPageUrl', ''));
        $config->setXProfileUrl($request->request->get('xProfileUrl', ''));
        $config->setBetaRequestEmail($request->request->get('betaRequestEmail', ''));
        $config->setRecaptchaSiteKey($request->request->get('recaptchaSiteKey', ''));
        $config->setRecaptchaSecretKey($request->request->get('recaptchaSecretKey', ''));
        $this->em->flush();

        $this->addFlash('success', 'App links saved.');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_app_links']));
    }

    // ── Social Account Connections ──────────────────────────────────────────

    #[Route('/admin/social', name: 'admin_social_connections')]
    #[IsGranted('ROLE_ADMIN')]
    public function socialConnections(): Response
    {
        return $this->render('admin/social_connections.html.twig', [
            'connections'      => $this->socialAccountConnectionRepository->findAllOrdered(),
            'templates'        => $this->socialPostTemplateRepository->findAllOrdered(),
            'previewTemplate'  => null,
            'previewText'      => null,
            'previewRequested' => false,
            'schedulePeriods'  => \App\Enum\StatsPeriod::cases(),
            'scheduleConfig'   => $this->gameConfigRepository->getConfig(),
        ]);
    }

    #[Route('/admin/social/schedule/save', name: 'admin_social_schedule_save', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function socialScheduleSave(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('save_social_schedule', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        $config = $this->gameConfigRepository->getConfig();
        $submitted = $request->request->all('schedule');

        foreach (\App\Enum\StatsPeriod::cases() as $period) {
            $row = $submitted[$period->value] ?? [];
            $enabled = !empty($row['enabled']);
            $intervalHours = max(1, (int) ($row['intervalHours'] ?? $config->getAutoPostIntervalHours($period)));
            $config->setAutoPostSchedule($period, $enabled, $intervalHours);
        }

        $this->em->flush();

        $this->addFlash('success', 'Auto-post schedule saved.');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
    }

    #[Route('/admin/social/test/preview', name: 'admin_social_test_preview', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function socialTestPreview(Request $request, \App\Service\SocialPostRenderer $renderer): Response
    {
        if (!$this->isCsrfTokenValid('social_test_preview', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        $template = $this->socialPostTemplateRepository->find($request->request->get('templateId'));
        if ($template === null) {
            $this->addFlash('danger', 'Template not found.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        $previewText = $renderer->render($template);

        return $this->render('admin/social_connections.html.twig', [
            'connections'      => $this->socialAccountConnectionRepository->findAllOrdered(),
            'templates'        => $this->socialPostTemplateRepository->findAllOrdered(),
            'previewTemplate'  => $template,
            'previewText'      => $previewText,
            'previewRequested' => true,
            'schedulePeriods'  => \App\Enum\StatsPeriod::cases(),
            'scheduleConfig'   => $this->gameConfigRepository->getConfig(),
        ]);
    }

    #[Route('/admin/social/test/publish', name: 'admin_social_test_publish', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function socialTestPublish(Request $request, \App\Service\SocialPostingService $postingService): Response
    {
        if (!$this->isCsrfTokenValid('social_test_publish', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        $template = $this->socialPostTemplateRepository->find($request->request->get('templateId'));
        $text     = (string) $request->request->get('renderedText', '');

        if ($template === null || $text === '') {
            $this->addFlash('danger', 'Nothing to publish — preview a template first.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        $targets = array_filter(
            $this->socialAccountConnectionRepository->findAllActive(),
            fn (\App\Entity\SocialAccountConnection $c) => $c->getPlatform() === $template->getPlatform(),
        );

        if (empty($targets)) {
            $this->addFlash('warning', "No active {$template->getPlatform()->value} connections to publish to.");
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        $posted = 0;
        foreach ($targets as $connection) {
            try {
                $postingService->post($connection, $text);
                $posted++;
            } catch (\App\Exception\SocialPostingException $e) {
                $this->addFlash('danger', "Failed to publish to {$connection->getDisplayName()}: {$e->getMessage()}");
            }
        }

        if ($posted > 0) {
            $this->addFlash('success', "Published to {$posted} connection(s).");
        }

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
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

        $config->setBondMin((int) $request->request->get('bondMin', -100));
        $config->setBondMax((int) $request->request->get('bondMax', 100));
        $config->setBondNegativeDriftPerWeek((int) $request->request->get('bondNegativeDriftPerWeek', 1));
        $config->setBondPositiveDecayPerWeek((int) $request->request->get('bondPositiveDecayPerWeek', 1));
        $config->setBondDecayFloor((int) $request->request->get('bondDecayFloor', 50));
        $config->setBondNeglectWeeks((int) $request->request->get('bondNeglectWeeks', 8));
        $config->setBondNegativeRelationsThreshold((int) $request->request->get('bondNegativeRelationsThreshold', -20));
        $config->setBondMoraleDecayChance((float) $request->request->get('bondMoraleDecayChance', 0.1));
        $config->setBondMoraleDecayPenalty((int) $request->request->get('bondMoraleDecayPenalty', 5));
        $config->setBondOrganicGrowthBaseChance((float) $request->request->get('bondOrganicGrowthBaseChance', 0.05));
        $config->setBondOrganicGrowthLoyaltyDivisor((int) $request->request->get('bondOrganicGrowthLoyaltyDivisor', 20));
        $config->setBondOrganicGrowthAbilityDivisor((int) $request->request->get('bondOrganicGrowthAbilityDivisor', 100));
        $config->setBondOrganicGrowthDelta((int) $request->request->get('bondOrganicGrowthDelta', 5));
        $config->setBondManagerAmpDelta((int) $request->request->get('bondManagerAmpDelta', 5));
        $config->setBondIncidentNegativeDelta((int) $request->request->get('bondIncidentNegativeDelta', -10));
        $config->setBondIncidentPositiveDelta((int) $request->request->get('bondIncidentPositiveDelta', 8));
        $config->setCoachManagerTrustThreshold((int) $request->request->get('coachManagerTrustThreshold', -20));
        $config->setCoachManagerTrustDecayChance((float) $request->request->get('coachManagerTrustDecayChance', 0.15));
        $config->setCoachManagerTrustMoralePenalty((int) $request->request->get('coachManagerTrustMoralePenalty', 5));
        $config->setBaseXP((int) $request->request->get('baseXP', 10));
        $config->setBaseInjuryProbability((float) $request->request->get('baseInjuryProbability', 0.05));
        $config->setRegressionUpperThreshold((int) $request->request->get('regressionUpperThreshold', 14));
        $config->setRegressionLowerThreshold((int) $request->request->get('regressionLowerThreshold', 7));
        $config->setReputationDeltaBase((float) $request->request->get('reputationDeltaBase', 0.15));
        $config->setReputationDeltaFacilityMultiplier((float) $request->request->get('reputationDeltaFacilityMultiplier', 0.15));
        $config->setInjuryMinorWeight((int) $request->request->get('injuryMinorWeight', 60));
        $config->setInjuryModerateWeight((int) $request->request->get('injuryModerateWeight', 30));
        $config->setInjurySeriousWeight((int) $request->request->get('injurySeriousWeight', 10));
        $config->setPotentialOvershootMax((float) $request->request->get('potentialOvershootMax', 0.05));
        $config->setPotentialDecayRate((float) $request->request->get('potentialDecayRate', 0.5));
        $config->setCoachDevelopmentMaxMultiplier((float) $request->request->get('coachDevelopmentMaxMultiplier', 2.0));
        $config->setCoachDevelopmentMinSpecialism((int) $request->request->get('coachDevelopmentMinSpecialism', 20));
        $config->setCoachDevelopmentStackingFactor((float) $request->request->get('coachDevelopmentStackingFactor', 0.3));
        $config->setCoachMoraleInfluence((float) $request->request->get('coachMoraleInfluence', 0.5));

        // Facility Staff Morale
        $config->setFacilityMoraleFmBase((int) $request->request->get('facilityMoraleFmBase', 6));
        $config->setFacilityMoraleFmPerLevel((int) $request->request->get('facilityMoraleFmPerLevel', 2));
        $config->setFacilityMoraleNewBuildBonus((int) $request->request->get('facilityMoraleNewBuildBonus', 4));
        $config->setFacilityMoraleStaffShare((float) $request->request->get('facilityMoraleStaffShare', 0.34));
        $config->setFacilityMoraleStartMultiplier((float) $request->request->get('facilityMoraleStartMultiplier', 0.4));
        $config->setStaffMoraleBandFactorMid((float) $request->request->get('staffMoraleBandFactorMid', 0.6));
        $config->setStaffMoraleBandFactorHigh((float) $request->request->get('staffMoraleBandFactorHigh', 0.25));

        // Physical Degradation & Form
        $config->setAttributeHardCap((int) $request->request->get('attributeHardCap', 98));
        $config->setPhysicalDegradationAgeThreshold((int) $request->request->get('physicalDegradationAgeThreshold', 30));
        $config->setPhysicalDegradationRateMild((float) $request->request->get('physicalDegradationRateMild', 0.1));
        $config->setPhysicalDegradationRateModerate((float) $request->request->get('physicalDegradationRateModerate', 0.2));
        $config->setPhysicalDegradationRateSevere((float) $request->request->get('physicalDegradationRateSevere', 0.4));
        $config->setPhysicalDegradationPersonalityScale((float) $request->request->get('physicalDegradationPersonalityScale', 0.2));
        $config->setFormDevelopmentMatchCount((int) $request->request->get('formDevelopmentMatchCount', 5));
        $config->setFormDevelopmentThreshold((float) $request->request->get('formDevelopmentThreshold', 7.0));
        $config->setFormDevelopmentMultiplierMid((float) $request->request->get('formDevelopmentMultiplierMid', 0.6));
        $config->setFormDevelopmentMultiplierLow((float) $request->request->get('formDevelopmentMultiplierLow', 0.25));
        $config->setFormDevelopmentThresholdLow((float) $request->request->get('formDevelopmentThresholdLow', 5.0));

        $config->setScoutMoraleThreshold((int) $request->request->get('scoutMoraleThreshold', 40));
        $config->setScoutRevealWeeks((int) $request->request->get('scoutRevealWeeks', 2));
        $config->setScoutAbilityErrorRange((int) $request->request->get('scoutAbilityErrorRange', 30));
        $config->setScoutMaxAssignments((int) $request->request->get('scoutMaxAssignments', 5));
        $config->setScoutReportValidWeeks(
            (int) $request->request->get('scoutReportValidWeeks', 26)
        );
        $config->setScoutReportDecayRatePerWeek(
            (int) $request->request->get('scoutReportDecayRatePerWeek', 2)
        );
        $config->setNpcScoutRejectionCooldownWeeks(
            (int) $request->request->get('npcScoutRejectionCooldownWeeks', 52)
        );
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
        $config->setSeasonTicketHolderPercent((int) $request->request->get('seasonTicketHolderPercent', 60));

        // Facility construction
        $config->setConstructionTimeMultiplier((float) $request->request->get('constructionTimeMultiplier', 1.0));
        $config->setFacilityConstructionFailureChance((float) $request->request->get('facilityConstructionFailureChance', 0.05));
        $config->setFacilityConstructionFailureCostMin((float) $request->request->get('facilityConstructionFailureCostMin', 5.0));
        $config->setFacilityConstructionFailureCostMax((float) $request->request->get('facilityConstructionFailureCostMax', 20.0));
        $config->setFacilityConstructionFailureTimeMin((int) $request->request->get('facilityConstructionFailureTimeMin', 2));
        $config->setFacilityConstructionFailureTimeMax((int) $request->request->get('facilityConstructionFailureTimeMax', 6));

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

        // Wage multiplier tiers
        $rawTiers = $request->request->all('wageMultiplierTiers') ?: [];
        $tiers = [];
        foreach ($rawTiers as $tier) {
            $maxAbility = isset($tier['maxAbility']) && $tier['maxAbility'] !== '' ? (int) $tier['maxAbility'] : null;
            $tiers[] = [
                'maxAbility'       => $maxAbility,
                'playerMultiplier' => (float) ($tier['playerMultiplier'] ?? 1.0),
                'staffMultiplier'  => (float) ($tier['staffMultiplier'] ?? 1.0),
            ];
        }
        if (!empty($tiers)) {
            $config->setWageMultiplierTiers($tiers);
        }
        $randMin = (int) $request->request->get('contractValueRandMin', 10);
        $randMax = (int) $request->request->get('contractValueRandMax', 40);
        $config->setContractValueRandMin(min($randMin, $randMax));
        $config->setContractValueRandMax(max($randMin, $randMax));

        // Cards & Discipline
        $config->setYellowCardBaseChance((float) $request->request->get('yellowCardBaseChance', 0.08));
        $config->setRedCardBaseChance((float) $request->request->get('redCardBaseChance', 0.01));
        $config->setYellowCardAccumulationThreshold((int) $request->request->get('yellowCardAccumulationThreshold', 5));
        $config->setRedCardSuspensionMatches((int) $request->request->get('redCardSuspensionMatches', 1));
        $config->setTemperamentCardScale((float) $request->request->get('temperamentCardScale', 1.0));

        // Manager sacking
        $config->setManagerSackingWinRatioTrigger((float) $request->request->get('managerSackingWinRatioTrigger', 0.20));
        $config->setManagerSackingWinRatioRecovery((float) $request->request->get('managerSackingWinRatioRecovery', 0.25));
        $config->setManagerSackingMinGames((int) $request->request->get('managerSackingMinGames', 30));
        $config->setManagerSackingAttendancePenaltyPerWeek((float) $request->request->get('managerSackingAttendancePenaltyPerWeek', 0.05));

        // Inbox / notification frequencies
        $config->setFacilityMaintenanceFrequencyWeeks((int) $request->request->get('facilityMaintenanceFrequencyWeeks', 4));
        $config->setSystemNotificationFrequencyWeeks((int) $request->request->get('systemNotificationFrequencyWeeks', 8));

        // Financial Penalties
        $config->setBankruptcyDeductionsEnabled((bool) $request->request->get('bankruptcyDeductionsEnabled', false));
        $tiersJson = $request->request->get('bankruptcyDeductionTiers', '[]');
        $tiers = json_decode($tiersJson, true);
        if (is_array($tiers)) {
            $config->setBankruptcyDeductionTiers($tiers);
        }

        // League finances — form submits pounds; multiply by 100 to store pence
        // Pounds in the form, pence in the column — the same * 100 the sponsor bands do. The
        // "field missing from the POST" default is derived from the entity's own constant
        // rather than a second literal 250, so there is one figure to change.
        $config->setYouthScholarshipWagePence(
            (int) $request->request->get(
                'youthScholarshipWagePence',
                intdiv(GameConfig::DEFAULT_YOUTH_SCHOLARSHIP_WAGE_PENCE, 100),
            ) * 100,
        );
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

        // Sponsor & investor slot limits
        $maxSponsorsByTier = json_decode((string) $request->request->get('maxSponsorsByTier', ''), true);
        if (is_array($maxSponsorsByTier)) {
            $config->setMaxSponsorsByTier($maxSponsorsByTier);
        }
        $maxInvestorsByTier = json_decode((string) $request->request->get('maxInvestorsByTier', ''), true);
        if (is_array($maxInvestorsByTier)) {
            $config->setMaxInvestorsByTier($maxInvestorsByTier);
        }

        // Staff limits per club
        $config->setMaxCoachesPerClub((int) $request->request->get('maxCoachesPerClub', 15));
        $config->setMaxManagersPerClub((int) $request->request->get('maxManagersPerClub', 1));
        $config->setMaxDirectorsOfFootballPerClub((int) $request->request->get('maxDirectorsOfFootballPerClub', 1));
        $config->setMaxFacilityManagersPerClub((int) $request->request->get('maxFacilityManagersPerClub', 1));
        $config->setMaxChairmensPerClub((int) $request->request->get('maxChairmensPerClub', 1));
        $config->setMaxScoutsPerClub((int) $request->request->get('maxScoutsPerClub', 3));
        $config->setSignOnFeePercentMin((int) $request->request->get('signOnFeePercentMin', 0));
        $config->setSignOnFeePercentMax((int) $request->request->get('signOnFeePercentMax', 10));
        $config->setFreeTransfersPerSeason((int) $request->request->get('freeTransfersPerSeason', 3));

        $config->setCapacityCalculation((int) $request->request->get('capacityCalculation', 100));

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

        // Squad Roles
        $roleFields = [
            'squadRoleAppearanceExpectations' => 'setSquadRoleAppearanceExpectations',
            'squadRoleMoraleDecayPerWeek'     => 'setSquadRoleMoraleDecayPerWeek',
            'squadRoleMoraleBoostPerWeek'     => 'setSquadRoleMoraleBoostPerWeek',
            'squadRoleAutoAssignThresholds'   => 'setSquadRoleAutoAssignThresholds',
        ];
        foreach ($roleFields as $field => $setter) {
            $decoded = json_decode($request->request->get($field, '{}'), true);
            if (is_array($decoded)) {
                $config->$setter($decoded);
            }
        }

        // NPC club balance ranges — form submits pounds; store pence (* 100)
        $rawBalanceRanges = $request->request->all()['npcBalanceRange'] ?? [];
        $balanceRanges = [];
        for ($t = 1; $t <= 8; $t++) {
            $minPounds = max(0, (int) ($rawBalanceRanges[$t]['min'] ?? 0));
            $maxPounds = max(0, (int) ($rawBalanceRanges[$t]['max'] ?? 0));
            $minPounds = min($minPounds, max($minPounds, $maxPounds)); // min ≤ max
            $balanceRanges[] = ['min' => $minPounds * 100, 'max' => max($minPounds, $maxPounds) * 100];
        }
        if (!empty(array_filter($balanceRanges))) {
            $config->setNpcClubBalanceRanges($balanceRanges);
        }

        // Player Development Schedule
        $config->setDevelopmentTickFrequencyWeeks((int) $request->request->get('developmentTickFrequencyWeeks', 1));

        // Feature Cooldowns
        $config->setAddressSquadCooldownWeeks((int) $request->request->get('addressSquadCooldownWeeks', 4));
        $config->setFanEventCooldownWeeks((int) $request->request->get('fanEventCooldownWeeks', 2));
        $config->setSponsorOfferCooldownWeeks((int) $request->request->get('sponsorOfferCooldownWeeks', 8));
        $config->setInvestorOfferCooldownWeeks((int) $request->request->get('investorOfferCooldownWeeks', 12));
        $config->setScoutReportCooldownWeeks((int) $request->request->get('scoutReportCooldownWeeks', 2));
        $config->setYouthIntakeCooldownWeeks((int) $request->request->get('youthIntakeCooldownWeeks', 12));
        $config->setPlayerInteractionCooldownWeeks((int) $request->request->get('playerInteractionCooldownWeeks', 2));
        $config->setStaffMeetingCooldownWeeks((int) $request->request->get('staffMeetingCooldownWeeks', 4));

        // Pyramid News
        $config->setPyramidNewsFrequencyWeeks((int) $request->request->get('pyramidNewsFrequencyWeeks', 4));
        $pyramidNewsConfigJson = $request->request->get('pyramidNewsConfig', '{}');
        $pyramidNewsConfig = json_decode($pyramidNewsConfigJson, true);
        if (is_array($pyramidNewsConfig)) {
            $config->setPyramidNewsConfig($pyramidNewsConfig);
        }

        // Competition history — league win points per tier
        $rawLeagueWinPoints = $request->request->all('leagueWinPoints') ?: [];
        if (!empty($rawLeagueWinPoints)) {
            $leagueWinPoints = [];
            for ($tier = 1; $tier <= 8; $tier++) {
                $leagueWinPoints[$tier] = max(0, (int) ($rawLeagueWinPoints[$tier] ?? 0));
            }
            $config->setLeagueWinPoints($leagueWinPoints);
        }

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

        // The ACCOUNT's opening credit range — distinct from the club kitty above. Set as a
        // pair, because the setter normalises a transposed min/max rather than refusing the
        // whole form over it.
        $config->setUserStartingBalanceRangePounds(
            (int) $request->request->get('userStartingBalanceMinPounds', 5_000_000),
            (int) $request->request->get('userStartingBalanceMaxPounds', 6_000_000),
        );
        // Zero is a meaningful value here (unlimited), so the default on a missing field has to
        // be zero too — falling back to a positive number would impose a ceiling nobody typed.
        $config->setMaxEnrichmentRequestsPerUser((int) $request->request->get('maxEnrichmentRequestsPerUser', 0));

        // Setup flow: what the onboarding quiz pays, and whether a club stake has a floor.
        // Both fall back to the "off"/default value on a missing field rather than to something
        // positive — a quiz bonus or a funding minimum nobody typed would change what players
        // are paid and what they are allowed to do, silently.
        $config->setQuizBonusMultiplier((float) $request->request->get('quizBonusMultiplier', 1.0));
        // Pounds in the form, pence in the column — same convention as the balances above.
        // Zero means "no minimum", so zero is the right fallback.
        $config->setClubFundingMinimumPence((int) $request->request->get('clubFundingMinimumPounds', 0) * 100);
        $config->setClubFundingProjectionWeeks((int) $request->request->get('clubFundingProjectionWeeks', 13));
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

        // Setup tutorial — a JSON textarea, same shape as Default Facilities above.
        //
        // Two deliberate differences. The setter ignores un-decodable input rather than
        // writing `[]`, so a typo in a 15-item blob cannot silently delete the tutorial; and
        // the parse failure is reported here, because the CLIENT reports nothing — its
        // `parseTutorial` cannot throw (setup is the one route a new account cannot skip), so
        // it drops bad items in silence. `app:check-setup-tutorial` is the deeper check;
        // this only catches "is it JSON at all".
        $tutorialRaw = trim((string) $request->request->get('setupTutorialConfig', ''));
        if ($tutorialRaw !== '') {
            $decodedTutorial = json_decode($tutorialRaw, true);
            if (!is_array($decodedTutorial)) {
                $this->addFlash('danger', 'Setup Tutorial contains invalid JSON and was not saved. Please fix it and try again.');
            } else {
                $config->setSetupTutorialConfigJson($tutorialRaw);
                foreach (SetupTutorialValidator::problems($decodedTutorial) as $problem) {
                    $this->addFlash('warning', 'Setup Tutorial: ' . $problem);
                }
            }
        }

        $npcConfigInput = $request->request->all('npcConfig') ?: [];
        $npcSquadConfig = [];
        foreach ($npcConfigInput as $tier => $fields) {
            $npcSquadConfig[$tier] = [
                'playerMin'               => (int) ($fields['playerMin'] ?? 15),
                'playerMax'               => (int) ($fields['playerMax'] ?? 25),
                'managerCount'            => (int) ($fields['managerCount'] ?? 1),
                'coachCount'              => (int) ($fields['coachCount'] ?? 1),
                'chairmanCount'           => (int) ($fields['chairmanCount'] ?? 1),
                'directorOfFootballCount' => (int) ($fields['directorOfFootballCount'] ?? 0),
                'facilityManagerCount'    => (int) ($fields['facilityManagerCount'] ?? 0),
                'scoutCount'              => (int) ($fields['scoutCount'] ?? 0),
                'foreignPercent'          => (int) ($fields['foreignPercent'] ?? 0),
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

        // enabledCountries is no longer submitted or stored: a country is offered to players
        // when its CountryProfile is LIVE. See CountryProfileRepository::liveCountryCodes().

        $ranges = $request->request->all('leagueRanges') ?: [];
        // Basic validation: ensure values are numeric
        foreach ($ranges as $country => &$countryTiers) {
            foreach ($countryTiers as $tier => &$bounds) {
                $bounds['min'] = (int) ($bounds['min'] ?? 1);
                $bounds['max'] = (int) ($bounds['max'] ?? 100);
            }
        }
        // Write league ability ranges directly — Doctrine's JSON change-detection
        // can silently skip updates when the stored data has mixed string/int types.
        $this->em->getConnection()->executeStatement(
            'UPDATE starter_config SET league_ability_ranges = :val WHERE id = :id',
            ['val' => json_encode($ranges), 'id' => $config->getId()]
        );

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

    // ── Translations Import / Export ─────────────────────────────────────

    #[Route('/admin/translations/content', name: 'admin_translations_content')]
    #[IsGranted('ROLE_ADMIN')]
    public function translationsContent(LanguageRepository $languageRepository): Response
    {
        return $this->render('admin/translations_content.html.twig', [
            'languages' => $languageRepository->findEnabled(),
        ]);
    }

    #[Route('/admin/translations/export', name: 'admin_translations_export', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function translationsExport(Request $request, LanguageRepository $languageRepository, TranslationCatalogueImportExportService $service): Response
    {
        $language = $languageRepository->findByCode((string) $request->query->get('language', ''));
        $default  = $languageRepository->findDefault();

        if ($language === null || $default === null) {
            $this->addFlash('danger', 'Choose a valid language to export.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_translations_content']));
        }

        $data     = $service->export($language, $default);
        $filename = 'wunderkind-translations-' . $language->getCode() . '-' . date('Y-m-d') . '.json';
        $json     = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $response = new StreamedResponse(function () use ($json) {
            echo $json;
        });

        $disposition = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    #[Route('/admin/translations/import', name: 'admin_translations_import', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function translationsImport(Request $request, LanguageRepository $languageRepository, TranslationCatalogueImportExportService $service): Response
    {
        if (!$this->isCsrfTokenValid('translations_import', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_translations_content']));
        }

        $target = $languageRepository->findByCode((string) $request->request->get('language', ''));
        if ($target === null) {
            $this->addFlash('danger', 'Choose a valid target language for this import.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_translations_content']));
        }

        $file = $request->files->get('translations_file');
        if ($file === null || !$file->isValid()) {
            $this->addFlash('danger', 'No valid file uploaded.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_translations_content']));
        }

        $raw = file_get_contents($file->getPathname());
        if ($raw === false) {
            $this->addFlash('danger', 'Could not read uploaded file.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_translations_content']));
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->addFlash('danger', 'Invalid JSON — could not parse the uploaded file.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_translations_content']));
        }

        $clearFirst = $request->request->has('clear_before_import');
        $result     = $service->import($data, $target, $clearFirst);

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->addFlash('warning', $error);
            }
        }

        if ($clearFirst) {
            $this->addFlash('warning', sprintf(
                'Cleared %d existing %s value(s) before importing; %d key(s) with no remaining translation in any language were removed entirely.',
                $result['cleared'],
                $target->getCode(),
                $result['prunedKeys'],
            ));
        }

        $this->addFlash('success', sprintf(
            'Import complete — %d created, %d updated.',
            $result['created'],
            $result['updated'],
        ));

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_translations_content']));
    }

    // ── NPC Clubs ─────────────────────────────────────────────────────────

    #[Route('/admin/npc-clubs/content', name: 'admin_npc_clubs_content')]
    #[IsGranted('ROLE_ADMIN')]
    public function npcClubsContent(): Response
    {
        $config      = $this->poolConfigRepository->getConfig();
        $gameConfig  = $this->gameConfigRepository->getConfig();
        $facilities  = $this->facilityTemplateRepository->findBy(['isActive' => true]);

        return $this->render('admin/npc_clubs_content.html.twig', [
            'config'               => $config,
            'gameConfig'           => $gameConfig,
            'facilities'           => $facilities,
            'clubCount'            => $this->npcClubRepository->count([]),
            'clubsByCountry'       => $this->npcClubRepository->getCountsByCountryAndTier(),
            'generationCapableCountries' => Country::generationCapableLabels(),
        ]);
    }

    #[Route('/admin/npc-clubs/save-facility-config', name: 'admin_npc_clubs_save_facility_config', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function saveNpcFacilityConfig(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('npc_facility_config', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
        }

        $gameConfig  = $this->gameConfigRepository->getConfig();
        $rawBands    = $request->request->all('facilityRanges');
        $bands       = [];

        for ($band = 0; $band < 4; $band++) {
            $bandData = $rawBands[$band] ?? [];
            foreach ($bandData as $slug => $minMax) {
                $min = max(0, (int) ($minMax['min'] ?? 0));
                $max = max($min, (int) ($minMax['max'] ?? 0));
                $bands[$band][$slug] = ['min' => $min, 'max' => $max];
            }
        }

        $gameConfig->setNpcFacilityLevelRanges($bands);
        $this->em->flush();

        $this->addFlash('success', 'Facility level ranges saved.');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
    }

    #[Route('/admin/npc-clubs/save-size-weights', name: 'admin_npc_clubs_save_size_weights', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function saveNpcClubSizeWeights(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('npc_size_weights', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
        }

        $gameConfig = $this->gameConfigRepository->getConfig();
        $raw        = $request->request->all('sizeWeights');

        $weights = [];
        foreach (['tier1', 'tier8'] as $row) {
            $rowData = $raw[$row] ?? [];
            $weights[$row] = [
                'big'    => max(0, (int) ($rowData['big'] ?? 0)),
                'medium' => max(0, (int) ($rowData['medium'] ?? 0)),
                'small'  => max(0, (int) ($rowData['small'] ?? 0)),
            ];
        }

        $gameConfig->setNpcClubSizeWeights($weights);
        $this->em->flush();

        $this->addFlash('success', 'City size weights saved.');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
    }

    #[Route('/admin/leagues/overview', name: 'admin_leagues_overview')]
    #[IsGranted('ROLE_ADMIN')]
    public function leaguesOverview(): Response
    {
        $leagues = $this->leagueRepository->findAllWithSponsors();

        $leaguesByCountry = [];
        foreach ($leagues as $league) {
            $leaguesByCountry[$league->getCountry()][$league->getTier()] = $league;
        }
        ksort($leaguesByCountry);

        // Flat list for the "League Tier Defaults" bulk-apply picker, grouped the same way.
        usort($leagues, fn ($a, $b) => [$a->getCountry(), $a->getTier()] <=> [$b->getCountry(), $b->getTier()]);

        return $this->render('admin/leagues_content.html.twig', [
            'leaguesByCountry' => $leaguesByCountry,
            'bmcGroup'         => 'World',
            'bmcTitle'         => 'League overview',
            // Which leagues have drifted from the tier row they are meant to follow, keyed by
            // league id. One map feeds the grid's orange cells, the country tabs' counts and
            // the per-field marks in the pyramids, so there is exactly one definition of
            // "differs". No extra queries: every League is already loaded above.
            'leagueDiffs'      => LeagueTierDefaultDiff::forLeagues($leagues, $this->gameConfigRepository->getConfig()),
            'clubsByLeague'    => $this->npcClubRepository->getAllGroupedByLeague(),
            'countryLabels'    => Country::labelMap(),
            'allLeagues'       => $leagues,
            'tierDefaults'     => $this->gameConfigRepository->getConfig()->getLeagueTierDefaults(),
            // Trophy pickers (per-tier defaults table + per-league quick-edit modal)
            // build their option lists from the enums, which mirror the trophy
            // sprite's own TROPHY_STYLES/TROPHY_FINISHES/KIT_COLORS — never from a
            // hand-written list in the template. Same rule as the country/nationality
            // lists (see CLAUDE.md's World Generation note).
            'trophyStyles'     => array_map(
                static fn (TrophyStyle $s): array => ['value' => $s->value, 'label' => $s->label()],
                TrophyStyle::cases(),
            ),
            'trophyFinishes'   => array_map(
                static fn (TrophyFinish $f): array => ['value' => $f->value, 'label' => $f->label()],
                TrophyFinish::cases(),
            ),
            'trophyRibbonColours' => TrophyConfigFactory::ribbonColours(),
            'trophyDefaults'   => TrophyConfigFactory::defaults(),
        ]);
    }

    // ── Facilities Overview ───────────────────────────────────────────────

    #[Route('/admin/facilities/overview', name: 'admin_facilities_overview')]
    #[IsGranted('ROLE_ADMIN')]
    public function facilitiesOverview(): Response
    {
        $facilities = $this->facilityTemplateRepository->findAllOrderedByCategoryAndSort();

        $byCategory = [];
        foreach ($facilities as $f) {
            $byCategory[$f->getCategory()][] = $f;
        }
        ksort($byCategory);

        return $this->render('admin/facilities_content.html.twig', [
            'facilities' => $facilities,
            'byCategory' => $byCategory,
        ]);
    }

    #[Route('/admin/npc-clubs/generate', name: 'admin_npc_clubs_generate', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function generateNpcClubs(Request $request): Response
    {
        $isAjax = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('generate_npc_clubs', $request->request->get('_token'))) {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'Invalid CSRF token.'], 400);
            }
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
        }

        $country        = strtoupper(trim($request->request->get('country', '')));
        $tier           = (int) $request->request->get('tier', 4);
        $count          = (int) $request->request->get('count', 8);
        $deleteExisting = (bool) $request->request->get('delete_existing', false);

        if ($country === '' || $tier < 1 || $tier > 8 || $count < 1) {
            if ($isAjax) {
                return $this->json(['success' => false, 'message' => 'Invalid parameters — country, tier (1–8) and count are required.'], 422);
            }
            $this->addFlash('danger', 'Invalid parameters — country, tier (1–8) and count are required.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_npc_clubs_content']));
        }

        $clubs  = $this->npcClubGenerationService->generateClubs($count, $tier, $country, $deleteExisting);
        $suffix = $deleteExisting ? ' (existing deleted first)' : '';
        $msg    = sprintf('Generated %d clubs for %s — Tier %d%s.', count($clubs), $country, $tier, $suffix);

        if ($isAjax) {
            return $this->json([
                'success'        => true,
                'message'        => $msg,
                'clubCount'      => $this->npcClubRepository->count([]),
                'clubsByCountry' => $this->npcClubRepository->getCountsByCountryAndTier(),
            ]);
        }

        $this->addFlash('success', $msg);
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

        // Clamped rather than rejected: the form already bounds the input, and an out-of-range
        // value here means a hand-crafted request, not an operator who needs an error page.
        $tiers = (int) ($request->request->get('tiers') ?: LeagueService::MAX_TIERS);
        $tiers = max(1, min(LeagueService::MAX_TIERS, $tiers));

        $result = $this->leagueService->generateLeaguesForCountry($country, $tiers);

        // Record the DECLARED depth, which is what NPC quality is scaled against. Without
        // this a 3-tier country's bottom division would be generated as a comfortable
        // mid-table one.
        $profile = $this->countryProfileRepository->findByCode($country);
        if ($profile !== null) {
            $profile->setLeagueTierCount($tiers);
            $this->em->flush();
        }

        $msg = sprintf('%s now has a %d-tier structure: %d created', $country, $tiers, count($result['created']));
        if ($result['skipped'] > 0) {
            $msg .= sprintf(', %d already existed', $result['skipped']);
        }
        if ($result['removedTiers'] > 0) {
            // Said plainly, because this is the destructive half and the operator should
            // see what went with it rather than discover it later.
            $msg .= sprintf(
                ', %d surplus tier(s) removed along with %d pool club(s)',
                $result['removedTiers'],
                $result['removedClubs'],
            );
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

    // ── Worldpack Cache ───────────────────────────────────────────────────

    #[Route('/admin/worldpack-cache', name: 'admin_worldpack_cache')]
    #[IsGranted('ROLE_ADMIN')]
    public function worldpackCache(): Response
    {
        $summaries = $this->worldPackCacheRepository->findAllSummaries();

        $entries   = [];
        $byCountry = [];

        foreach ($summaries as $row) {
            $entries[] = $row;
            $byCountry[$row['country']][$row['tier']] = true;
        }

        // Cache warming only makes sense for countries the league/club
        // generation process can actually build - independent of
        // StarterConfig's player-facing "enabled countries" toggle.
        $enabledCountries = Country::generationCapableLabels();

        return $this->render('admin/worldpack_cache.html.twig', [
            'entries'          => $entries,
            'byCountry'        => $byCountry,
            'totalEntries'     => count($entries),
            'enabledCountries' => $enabledCountries,
            'countryLabels'    => Country::labelMap(),
        ]);
    }

    // ── Developer Tools ───────────────────────────────────────────────────

    #[Route('/admin/developer-tools/trigger-age21', name: 'admin_trigger_age21', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function triggerAge21Deletion(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('trigger_age21', $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_settings');
        }

        $this->addFlash('info', 'Age-21 deletion is now handled automatically during sync. Pool players are not club-owned and are deleted on consumption.');
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

    /**
     * Deletes every spoof club and its throwaway owning account (app:clubs:purge-spoof).
     *
     * Unlike its neighbours here, this one posts to and redirects through EasyAdmin's entry
     * point (/admin?routeName=...). settings.html.twig extends @EasyAdmin/layout.html.twig,
     * and the `ea` Twig context is only populated for routes EasyAdmin itself created — see
     * src/Controller/Admin/CLAUDE.md. The older buttons on this screen still use the plain
     * route; they are not changed here, but new ones should not copy that.
     */
    #[Route('/admin/developer-tools/purge-spoof-clubs', name: 'admin_purge_spoof_clubs', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function purgeSpoofClubs(Request $request, KernelInterface $kernel): Response
    {
        $settingsUrl = $this->generateUrl('admin', ['routeName' => 'admin_settings']);

        if (!$this->isCsrfTokenValid('purge_spoof_clubs', $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');

            return $this->redirect($settingsUrl);
        }

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input  = new ArrayInput(['command' => 'app:clubs:purge-spoof']);
        $output = new BufferedOutput();
        $application->run($input, $output);

        $this->addFlash('success', 'Spoof clubs purged.');
        $this->addFlash('info', nl2br(htmlspecialchars(trim($output->fetch()))));

        return $this->redirect($settingsUrl);
    }

    #[Route('/admin/developer-tools/generate-leaderboards', name: 'admin_generate_leaderboards', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function generateLeaderboards(Request $request, KernelInterface $kernel): Response
    {
        if (!$this->isCsrfTokenValid('generate_leaderboards', $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_settings']));
        }

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input  = new ArrayInput(['command' => 'app:leaderboards:generate']);
        $output = new BufferedOutput();
        $application->run($input, $output);

        $this->addFlash('success', 'Leaderboard generation executed.');
        $this->addFlash('info', nl2br(htmlspecialchars(trim($output->fetch()))));

        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_settings']));
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
        $conn->executeStatement('DELETE FROM match_result');
        $conn->executeStatement('DELETE FROM season_record');
        $conn->executeStatement('DELETE FROM season_snapshot');
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

    // ── Telemetry Config ──────────────────────────────────────────────────

    #[Route('/admin/telemetry-config', name: 'admin_telemetry_config')]
    #[IsGranted('ROLE_ADMIN')]
    public function telemetryConfig(\App\Service\LiveTelemetryService $liveTelemetryService): Response
    {
        return $this->render('admin/telemetry_config.html.twig', [
            'snapshot' => $liveTelemetryService->getSnapshot(),
        ]);
    }

    #[Route('/admin/telemetry-config/rebuild', name: 'admin_telemetry_rebuild', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function rebuildTelemetrySnapshot(Request $request, \App\Service\LiveTelemetryService $liveTelemetryService): Response
    {
        if (!$this->isCsrfTokenValid('rebuild_telemetry', $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_telemetry_config']));
        }

        $liveTelemetryService->refresh();

        $this->addFlash('success', 'Telemetry snapshot rebuilt.');
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_telemetry_config']));
    }

    // ── Import / Export hub ──────────────────────────────────────────────

    /**
     * One page for all four import/export domains.
     *
     * The sidebar used to carry four separate entries, every one of them
     * labelled "Import / Export" with the same icon, sitting in four different
     * submenus — so finding a domain's file meant already knowing which group
     * it lived under. This is the index over them.
     *
     * Nothing moved behind the scenes: each card still posts to its own
     * existing route with its own CSRF token, and the per-domain pages are
     * still reachable (the cards link to them) for their longer notes on what
     * each import actually does.
     */
    #[Route('/admin/import-export', name: 'admin_import_export')]
    #[IsGranted('ROLE_ADMIN')]
    public function importExport(LanguageRepository $languageRepository): Response
    {
        return $this->render('admin/import_export.html.twig', [
            // Only the translations card needs these, but the template reads
            // them unconditionally to keep its empty-state check simple.
            'languages' => $languageRepository->findEnabled(),
        ]);
    }

    // ── EasyAdmin configuration ───────────────────────────────────────────

    /**
     * Hand-bumped cache-buster for the two redesign assets. There is no asset
     * pipeline in this project (no importmap, no Encore), so public/ files are
     * served verbatim with no versioning of their own. Bump this on every edit
     * to public/bmc-admin.{css,js} or public/bmc-pages.{css,js}. Same approach
     * as templates/admin/dashboard.html.twig's dash_v.
     */
    private const BMC_ASSET_VERSION = '20261107d';

    public function configureAssets(): Assets
    {
        return Assets::new()
            // admin-theme.css still dresses the pages that have not been
            // redesigned yet; bmc-admin.css loads after it so it wins.
            ->addCssFile('admin-theme.css')
            ->addCssFile('bmc-admin.css?v=' . self::BMC_ASSET_VERSION)
            // The component layer for the redesigned NON-list pages. After
            // bmc-admin.css because it builds on its tokens (--wk-*, --text-*)
            // and extends its .bmc-btn / .bmc-badge / .bmc-seg / .bmc-input.
            // Harmless on pages that do not use it: everything it defines is a
            // bmc- class nothing else carries.
            ->addCssFile('bmc-pages.css?v=' . self::BMC_ASSET_VERSION)
            // All three are deferred, and that is load-bearing rather than an
            // optimisation. EasyAdmin emits configureAssets() scripts in <head>,
            // well before the shell markup exists, and bmc-admin.js is a plain
            // IIFE that wires everything up the moment it runs — icons,
            // accordion, pinned/recent, palette, clock, filter rail, row
            // selection. Without defer it runs against an empty document and
            // every one of those is silently a no-op. (The handoff's reference
            // page loads it at the end of <body>, which has the same effect.)
            //
            // defer also preserves execution order, so the dependency chain
            // below still holds.
            ->addJsFile(Asset::new('lucide.min.js')->defer())
            // window.composeKitSvg — draws club badges from their kit config.
            ->addJsFile(Asset::new('assets/kit-compositor.js')->defer())
            // Must run after the two above: it calls lucide.createIcons() and
            // composeKitSvg() on the way in.
            ->addJsFile(Asset::new('bmc-admin.js?v=' . self::BMC_ASSET_VERSION)->defer())
            // window.BMC — toast, confirm (PixelDialog), runJobs, money, and the
            // data-bmc-* form behaviour the redesigned pages are built on. Last,
            // and deferred for the same reason as the rest: it binds on load.
            // It defines no global the shell already uses, and every binding is
            // opt-in by attribute, so pages carrying none are untouched.
            ->addJsFile(Asset::new('bmc-pages.js?v=' . self::BMC_ASSET_VERSION)->defer());
        // admin-ui.js is gone: it only gave EasyAdmin's sidebar-collapse handle
        // real button semantics, and the redesigned shell replaces that sidebar.
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            // Plain text now. The logo is rendered by the shell's own
            // .bmc-brand markup in layout.html.twig rather than smuggled in
            // through the title, so this only has to serve <title> and the
            // responsive header.
            ->setTitle('Build My Club')
            ->setFaviconPath('logo-32.png')
            ->renderContentMaximized();
    }

    /**
     * The sidebar.
     *
     * Three zones (OPERATE / WORLD / SYSTEM) over nine groups, which the
     * redesigned menu template renders as an accordion with one group open at a
     * time. See assets/design_handoff_admin_overhaul/README.md §5.1 for the
     * grouping and what moved where.
     *
     * Icons are Lucide names, and they sit on group headings only — page links
     * are text. templates/bundles/EasyAdminBundle/menu.html.twig emits them as
     * <i data-lucide="..."> so EasyAdmin never gets to interpret them as Font
     * Awesome classes. Font Awesome still dresses action icons inside the CRUD
     * pages that have not been redesigned yet.
     *
     * A CrudController is NOT auto-discovered into this sidebar; it has to be
     * listed here or it has no way in.
     */
    public function configureMenuItems(): iterable
    {
        $queues = $this->adminQueueCounts->get();

        yield MenuItem::linkToDashboard('Dashboard', 'house');

        // ── OPERATE — the live game and the people in it ──────────────────
        yield MenuItem::section('Operate');

        yield MenuItem::subMenu('Accounts', 'users')->setSubItems([
            MenuItem::linkTo(UserCrudController::class, 'Users'),
            MenuItem::linkTo(ClubCrudController::class, 'Clubs'),
            MenuItem::linkTo(UserLedgerCrudController::class, 'User ledger'),
            MenuItem::linkTo(SyncRecordCrudController::class, 'Sync records'),
            self::queue(MenuItem::linkTo(BetaRequestCrudController::class, 'Beta requests'), $queues['beta']),
            self::queue(MenuItem::linkTo(DeletionRequestCrudController::class, 'Deletion requests'), $queues['deletion']),
        ]);
        yield MenuItem::subMenu('Seasons & competitions', 'trophy')->setSubItems([
            MenuItem::linkTo(ActiveCompetitionCrudController::class, 'Active competitions'),
            MenuItem::linkTo(CompetitionRoundCrudController::class, 'Rounds'),
            MenuItem::linkTo(FriendlyChallengeCrudController::class, 'Challenges'),
            MenuItem::linkTo(LeaderboardEntryCrudController::class, 'Leaderboard entries'),
            MenuItem::linkTo(SeasonRecordCrudController::class, 'Season records'),
            MenuItem::linkTo(SeasonSnapshotCrudController::class, 'Season snapshots'),
            MenuItem::linkTo(CompetitionEntrantCrudController::class, 'Club snapshots'),
            MenuItem::linkTo(LeaderboardPeriodArchiveCrudController::class, 'Period archives'),
            MenuItem::linkTo(LeaderboardPeriodArchiveEntryCrudController::class, 'Awards'),
        ]);
        yield MenuItem::subMenu('Comms', 'megaphone')->setSubItems([
            MenuItem::linkTo(AdminMessageCrudController::class, 'Announcements'),
            MenuItem::linkTo(AudienceGroupCrudController::class, 'Audience groups'),
            MenuItem::linkTo(NotificationLogCrudController::class, 'Notification logs'),
            MenuItem::linkToRoute('Social connections', null, 'admin_social_connections'),
        ]);

        // ── WORLD — the map, its inhabitants, and the content behind them ──
        yield MenuItem::section('World');

        yield MenuItem::subMenu('World', 'globe')->setSubItems([
            MenuItem::linkTo(LeagueCrudController::class, 'Leagues'),
            MenuItem::linkToRoute('League overview', null, 'admin_leagues_overview'),
            MenuItem::linkTo(NpcClubCrudController::class, 'NPC clubs'),
            MenuItem::linkTo(TacticalAdvantageCrudController::class, 'Tactical matrix'),
            MenuItem::linkToRoute('Generate', null, 'admin_npc_clubs_content'),
        ]);
        // Not in the design handoff's grouping, which predates this work: the
        // land-plot economy and its AI enrichment. Four live pages, so they get
        // their own group rather than being buried at the bottom of World.
        yield MenuItem::subMenu('Locations & land', 'map-pin')->setSubItems([
            MenuItem::linkTo(LocationCrudController::class, 'Locations'),
            MenuItem::linkTo(LandPlotCrudController::class, 'Land plots'),
            MenuItem::linkTo(CountryProfileCrudController::class, 'Countries'),
            MenuItem::linkToRoute('Enrichment', null, 'admin_location_enrichment'),
            MenuItem::linkToRoute('Configuration', null, 'admin_location_config'),
        ]);
        yield MenuItem::subMenu('People & market', 'person-standing')->setSubItems([
            MenuItem::linkTo(PlayerCrudController::class, 'Players'),
            MenuItem::linkTo(StaffCrudController::class, 'Staff'),
            MenuItem::linkTo(ScoutCrudController::class, 'Scouts'),
            MenuItem::linkTo(AgentCrudController::class, 'Agents'),
            MenuItem::linkTo(GuardianCrudController::class, 'Guardians'),
            MenuItem::linkTo(InvestorCrudController::class, 'Investors'),
            MenuItem::linkTo(SponsorCrudController::class, 'Sponsors'),
        ]);
        yield MenuItem::subMenu('Templates', 'scroll')->setSubItems([
            MenuItem::linkTo(CompetitionTemplateCrudController::class, 'Competition templates'),
            MenuItem::linkTo(RewardTemplateCrudController::class, 'Reward templates'),
            MenuItem::linkTo(LeaderboardAwardDefinitionCrudController::class, 'Award definitions'),
            MenuItem::linkTo(GameEventTemplateCrudController::class, 'Event templates'),
            MenuItem::linkToRoute('Facility templates', null, 'admin_facilities_overview'),
            MenuItem::linkTo(PlayerArchetypeCrudController::class, 'Player archetypes'),
            MenuItem::linkTo(ExcursionCrudController::class, 'Excursions'),
            MenuItem::linkTo(SocialPostTemplateCrudController::class, 'Post templates'),
        ]);
        yield MenuItem::subMenu('Translations', 'languages')->setSubItems([
            MenuItem::linkTo(LanguageCrudController::class, 'Languages'),
            MenuItem::linkTo(TranslationKeyCrudController::class, 'Translation keys'),
            MenuItem::linkTo(TranslationCrudController::class, 'Translations'),
        ]);

        // ── SYSTEM — the knobs and the plumbing ───────────────────────────
        yield MenuItem::section('System');

        yield MenuItem::subMenu('Config', 'sliders-horizontal')->setSubItems([
            MenuItem::linkToRoute('Starter config', null, 'admin_starter_config'),
            MenuItem::linkToRoute('Starter pack check', null, 'admin_starter_pack_check'),
            MenuItem::linkToRoute('Game config', null, 'admin_game_config'),
            // Also absent from the handoff's grouping; it is a config screen.
            MenuItem::linkTo(SetupQuizQuestionCrudController::class, 'Setup quiz'),
            MenuItem::linkToRoute('Player pool', null, 'admin_player_pool_config'),
            MenuItem::linkToRoute('Staff pool', null, 'admin_staff_pool_config'),
            MenuItem::linkToRoute('Investor pool', null, 'admin_investor_pool_config'),
            MenuItem::linkToRoute('Worldpack cache', null, 'admin_worldpack_cache'),
            MenuItem::linkToRoute('Telemetry config', null, 'admin_telemetry_config'),
            MenuItem::linkToRoute('App links', null, 'admin_app_links'),
        ]);
        yield MenuItem::subMenu('System', 'settings')->setSubItems([
            MenuItem::linkTo(AdminCrudController::class, 'Admins'),
            MenuItem::linkToRoute('Settings & tools', null, 'admin_settings'),
            MenuItem::linkToRoute('Logs', null, 'admin_logs'),
            MenuItem::linkToRoute('Debug tools', null, 'admin_notification_debug'),
            // One page now, replacing four menu entries that were all called
            // "Import / Export" in four different groups.
            MenuItem::linkToRoute('Import / Export', null, 'admin_import_export'),
        ]);
    }

    /**
     * Badge a queue page with how many rows are waiting, or leave it bare.
     *
     * A badge showing 0 is worse than no badge: it draws the eye to a page with
     * nothing to do on it.
     */
    private static function queue(ControllerMenuItem $item, int $count): ControllerMenuItem
    {
        // ControllerMenuItem, not MenuItem: MenuItem is the static factory, and
        // linkTo() hands back a ControllerMenuItem. setBadge() comes from
        // MenuItemTrait rather than MenuItemInterface, so the concrete type is
        // what has to be named here.
        return $count > 0 ? $item->setBadge($count, 'warning') : $item;
    }

}
