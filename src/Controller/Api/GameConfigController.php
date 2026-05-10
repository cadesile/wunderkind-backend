<?php

namespace App\Controller\Api;

use App\Repository\FacilityTemplateRepository;
use App\Repository\GameConfigRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class GameConfigController extends AbstractController
{
    public function __construct(
        private readonly GameConfigRepository      $gameConfigRepository,
        private readonly FacilityTemplateRepository $facilityTemplateRepository,
    ) {}

    #[Route('/game-config', name: 'api_game_config', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $config = $this->gameConfigRepository->getConfig(flush: true);

        return $this->json([
            'cliqueRelationshipThreshold'      => $config->getCliqueRelationshipThreshold(),
            'cliqueSquadCapPercent'             => $config->getCliqueSquadCapPercent(),
            'cliqueMinTenureWeeks'              => $config->getCliqueMinTenureWeeks(),
            'baseXP'                            => $config->getBaseXP(),
            'baseInjuryProbability'             => $config->getBaseInjuryProbability(),
            'regressionUpperThreshold'          => $config->getRegressionUpperThreshold(),
            'regressionLowerThreshold'          => $config->getRegressionLowerThreshold(),
            'reputationDeltaBase'               => $config->getReputationDeltaBase(),
            'reputationDeltaFacilityMultiplier' => $config->getReputationDeltaFacilityMultiplier(),
            'injuryMinorWeight'                 => $config->getInjuryMinorWeight(),
            'injuryModerateWeight'              => $config->getInjuryModerateWeight(),
            'injurySeriousWeight'               => $config->getInjurySeriousWeight(),

            // Scouting
            'scoutMoraleThreshold'              => $config->getScoutMoraleThreshold(),
            'scoutRevealWeeks'                  => $config->getScoutRevealWeeks(),
            'scoutAbilityErrorRange'            => $config->getScoutAbilityErrorRange(),
            'scoutMaxAssignments'               => $config->getScoutMaxAssignments(),
            'missionGemRollThresholds'          => $config->getMissionGemRollThresholds(),
            'playerFeeMultiplier'               => $config->getPlayerFeeMultiplier(),
            'playerWageMultiplier'              => $config->getPlayerWageMultiplier(),
            'defaultMoraleMin'                  => $config->getDefaultMoraleMin(),
            'defaultMoraleMax'                  => $config->getDefaultMoraleMax(),

            // Incidents
            'incidentLowProfessionalismThreshold'        => $config->getIncidentLowProfessionalismThreshold(),
            'incidentLowProfessionalismChance'           => $config->getIncidentLowProfessionalismChance(),
            'incidentHighDeterminationThreshold'         => $config->getIncidentHighDeterminationThreshold(),
            'incidentHighDeterminationChance'            => $config->getIncidentHighDeterminationChance(),
            'incidentAltercationBaseChance'              => $config->getIncidentAltercationBaseChance(),
            'incidentAltercationSeriousBase'             => $config->getIncidentAltercationSeriousBase(),
            'incidentAltercationSeriousTemperamentScale' => $config->getIncidentAltercationSeriousTemperamentScale(),

            // Guardian complaints
            'guardianConvinceMoraleBoost'                  => $config->getGuardianConvinceMoraleBoost(),
            'guardianConvinceGuardianLoyaltyBoost'         => $config->getGuardianConvinceGuardianLoyaltyBoost(),
            'guardianConvinceGuardianDemandIncrease'       => $config->getGuardianConvinceGuardianDemandIncrease(),
            'guardianIgnoreMoralePenalty'                  => $config->getGuardianIgnoreMoralePenalty(),
            'guardianIgnoreLoyaltyTraitPenalty'            => $config->getGuardianIgnoreLoyaltyTraitPenalty(),
            'guardianIgnoreGuardianLoyaltyPenalty'         => $config->getGuardianIgnoreGuardianLoyaltyPenalty(),
            'guardianIgnoreGuardianDemandIncrease'         => $config->getGuardianIgnoreGuardianDemandIncrease(),
            'guardianIgnoreSiblingMoralePenalty'           => $config->getGuardianIgnoreSiblingMoralePenalty(),
            'guardianIgnoreSiblingLoyaltyTraitPenalty'     => $config->getGuardianIgnoreSiblingLoyaltyTraitPenalty(),

            // Facility income
            'nonMatchFacilityIncomePercent' => $config->getNonMatchFacilityIncomePercent(),

            // Playing style influence
            'playingStyleInfluence' => $config->getPlayingStyleInfluence(),

            // Player retirement
            'retirementMinAge'   => $config->getRetirementMinAge(),
            'retirementMaxAge'   => $config->getRetirementMaxAge(),
            'retirementChance'   => $config->getRetirementChance(),

            // Attendance ranges (% of stadium capacity per reputation tier)
            'attendanceLocalMin'    => $config->getAttendanceLocalMin(),
            'attendanceLocalMax'    => $config->getAttendanceLocalMax(),
            'attendanceRegionalMin' => $config->getAttendanceRegionalMin(),
            'attendanceRegionalMax' => $config->getAttendanceRegionalMax(),
            'attendanceNationalMin' => $config->getAttendanceNationalMin(),
            'attendanceNationalMax' => $config->getAttendanceNationalMax(),
            'attendanceEliteMin'    => $config->getAttendanceEliteMin(),
            'attendanceEliteMax'    => $config->getAttendanceEliteMax(),

            // Squad configuration
            'squadSizeMin' => $config->getSquadSizeMin(),
            'squadSizeMax' => $config->getSquadSizeMax(),

            // League player ability ranges per country/tier
            'leaguePlayerAbilityRanges' => $config->getLeaguePlayerAbilityRanges(),

            // Inbox / Notification frequencies
            'facilityMaintenanceFrequencyWeeks' => $config->getFacilityMaintenanceFrequencyWeeks(),
            'systemNotificationFrequencyWeeks'  => $config->getSystemNotificationFrequencyWeeks(),

            // Developer / Debug
            'debugLoggingEnabled'               => $config->isDebugLoggingEnabled(),

            // Wage multiplier tiers
            'wageMultiplierTiers'    => $config->getWageMultiplierTiers(),
            'contractValueRandMin'   => $config->getContractValueRandMin(),
            'contractValueRandMax'   => $config->getContractValueRandMax(),

            // Manager sacking
            'managerSackingWinRatioTrigger'           => $config->getManagerSackingWinRatioTrigger(),
            'managerSackingWinRatioRecovery'          => $config->getManagerSackingWinRatioRecovery(),
            'managerSackingMinGames'                  => $config->getManagerSackingMinGames(),
            'managerSackingAttendancePenaltyPerWeek'  => $config->getManagerSackingAttendancePenaltyPerWeek(),

            // League finances
            'smallSponsorMin'               => $config->getSmallSponsorMin(),
            'smallSponsorMax'               => $config->getSmallSponsorMax(),
            'mediumSponsorMin'              => $config->getMediumSponsorMin(),
            'mediumSponsorMax'              => $config->getMediumSponsorMax(),
            'largeSponsorMin'               => $config->getLargeSponsorMin(),
            'largeSponsorMax'               => $config->getLargeSponsorMax(),
            'leaguePositionDecreasePercent' => $config->getLeaguePositionDecreasePercent(),

            // Staff limits per club
            'maxCoachesPerClub'             => $config->getMaxCoachesPerClub(),
            'maxManagersPerClub'            => $config->getMaxManagersPerClub(),
            'maxDirectorsOfFootballPerClub' => $config->getMaxDirectorsOfFootballPerClub(),
            'maxFacilityManagersPerClub'    => $config->getMaxFacilityManagersPerClub(),
            'maxChairmensPerClub'           => $config->getMaxChairmensPerClub(),
            'maxScoutsPerClub'              => $config->getMaxScoutsPerClub(),

            // Sponsor & investor offer probabilities (by reputation tier)
            'sponsorProbabilityLocal'    => $config->getSponsorProbabilityLocal(),
            'sponsorProbabilityRegional' => $config->getSponsorProbabilityRegional(),
            'sponsorProbabilityNational' => $config->getSponsorProbabilityNational(),
            'sponsorProbabilityElite'    => $config->getSponsorProbabilityElite(),
            'investorProbabilityLocal'   => $config->getInvestorProbabilityLocal(),
            'investorProbabilityRegional'=> $config->getInvestorProbabilityRegional(),
            'investorProbabilityNational'=> $config->getInvestorProbabilityNational(),
            'investorProbabilityElite'   => $config->getInvestorProbabilityElite(),

            'staffRoles' => array_column(\App\Enum\StaffRole::cases(), 'value'),

            // Facility templates — defines per-level gameplay effects for each facility type.
            // Client applies these during the weekly tick on top of the GameConfig baselines above.
            'facilityTemplates' => array_map(
                fn($ft) => $ft->toArray(),
                $this->facilityTemplateRepository->getActiveTemplates()
            ),
        ]);
    }
}
