<?php

namespace App\Controller\Api;

use App\Repository\GameConfigRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class GameConfigController extends AbstractController
{
    public function __construct(
        private readonly GameConfigRepository $gameConfigRepository,
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

            // Developer / Debug
            'debugLoggingEnabled'                          => $config->isDebugLoggingEnabled(),
        ]);
    }
}
