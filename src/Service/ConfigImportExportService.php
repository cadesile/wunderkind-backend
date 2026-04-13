<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\GameConfigRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\StarterConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

class ConfigImportExportService
{
    private const EXPORT_VERSION = 1;

    public function __construct(
        private readonly GameConfigRepository    $gameConfigRepository,
        private readonly StarterConfigRepository $starterConfigRepository,
        private readonly PoolConfigRepository    $poolConfigRepository,
        private readonly EntityManagerInterface  $em,
    ) {}

    // ── Export ────────────────────────────────────────────────────────────────

    public function export(): array
    {
        $game    = $this->gameConfigRepository->getConfig();
        $starter = $this->starterConfigRepository->getConfig();
        $pool    = $this->poolConfigRepository->getConfig();

        return [
            'version'    => self::EXPORT_VERSION,
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'gameConfig' => [
                'cliqueRelationshipThreshold'              => $game->getCliqueRelationshipThreshold(),
                'cliqueSquadCapPercent'                    => $game->getCliqueSquadCapPercent(),
                'cliqueMinTenureWeeks'                     => $game->getCliqueMinTenureWeeks(),
                'baseXP'                                   => $game->getBaseXP(),
                'baseInjuryProbability'                    => $game->getBaseInjuryProbability(),
                'regressionUpperThreshold'                 => $game->getRegressionUpperThreshold(),
                'regressionLowerThreshold'                 => $game->getRegressionLowerThreshold(),
                'reputationDeltaBase'                      => $game->getReputationDeltaBase(),
                'reputationDeltaFacilityMultiplier'        => $game->getReputationDeltaFacilityMultiplier(),
                'injuryMinorWeight'                        => $game->getInjuryMinorWeight(),
                'injuryModerateWeight'                     => $game->getInjuryModerateWeight(),
                'injurySeriousWeight'                      => $game->getInjurySeriousWeight(),
                'scoutMoraleThreshold'                     => $game->getScoutMoraleThreshold(),
                'scoutRevealWeeks'                         => $game->getScoutRevealWeeks(),
                'scoutAbilityErrorRange'                   => $game->getScoutAbilityErrorRange(),
                'scoutMaxAssignments'                      => $game->getScoutMaxAssignments(),
                'missionGemRollThresholds'                 => $game->getMissionGemRollThresholds(),
                'playerFeeMultiplier'                      => $game->getPlayerFeeMultiplier(),
                'defaultMoraleMin'                         => $game->getDefaultMoraleMin(),
                'defaultMoraleMax'                         => $game->getDefaultMoraleMax(),
                'incidentLowProfessionalismThreshold'      => $game->getIncidentLowProfessionalismThreshold(),
                'incidentLowProfessionalismChance'         => $game->getIncidentLowProfessionalismChance(),
                'incidentHighDeterminationThreshold'       => $game->getIncidentHighDeterminationThreshold(),
                'incidentHighDeterminationChance'          => $game->getIncidentHighDeterminationChance(),
                'incidentAltercationBaseChance'            => $game->getIncidentAltercationBaseChance(),
                'incidentAltercationSeriousBase'           => $game->getIncidentAltercationSeriousBase(),
                'incidentAltercationSeriousTemperamentScale' => $game->getIncidentAltercationSeriousTemperamentScale(),
                'guardianConvinceMoraleBoost'              => $game->getGuardianConvinceMoraleBoost(),
                'guardianConvinceGuardianLoyaltyBoost'     => $game->getGuardianConvinceGuardianLoyaltyBoost(),
                'guardianConvinceGuardianDemandIncrease'   => $game->getGuardianConvinceGuardianDemandIncrease(),
                'guardianIgnoreMoralePenalty'              => $game->getGuardianIgnoreMoralePenalty(),
                'guardianIgnoreLoyaltyTraitPenalty'        => $game->getGuardianIgnoreLoyaltyTraitPenalty(),
                'guardianIgnoreGuardianLoyaltyPenalty'     => $game->getGuardianIgnoreGuardianLoyaltyPenalty(),
                'guardianIgnoreGuardianDemandIncrease'     => $game->getGuardianIgnoreGuardianDemandIncrease(),
                'guardianIgnoreSiblingMoralePenalty'       => $game->getGuardianIgnoreSiblingMoralePenalty(),
                'guardianIgnoreSiblingLoyaltyTraitPenalty' => $game->getGuardianIgnoreSiblingLoyaltyTraitPenalty(),
                'debugLoggingEnabled'                      => $game->isDebugLoggingEnabled(),
            ],
            'starterConfig' => [
                'startingBalance'    => $starter->getStartingBalance(),
                'starterPlayerCount' => $starter->getStarterPlayerCount(),
                'starterCoachCount'  => $starter->getStarterCoachCount(),
                'starterScoutCount'  => $starter->getStarterScoutCount(),
                'starterSponsorTier' => $starter->getStarterSponsorTier(),
                'starterAcademyTier' => $starter->getStarterAcademyTier(),
            ],
            'poolConfig' => [
                'playerAgeMin'              => $pool->getPlayerAgeMin(),
                'playerAgeMax'              => $pool->getPlayerAgeMax(),
                'playerPotentialMin'        => $pool->getPlayerPotentialMin(),
                'playerPotentialMax'        => $pool->getPlayerPotentialMax(),
                'playerPotentialMean'       => $pool->getPlayerPotentialMean(),
                'playerAbilityMin'          => $pool->getPlayerAbilityMin(),
                'playerAbilityMax'          => $pool->getPlayerAbilityMax(),
                'playerAttributeBudgetMin'  => $pool->getPlayerAttributeBudgetMin(),
                'playerAttributeBudgetMax'  => $pool->getPlayerAttributeBudgetMax(),
                'playerAgentChancePercent'  => $pool->getPlayerAgentChancePercent(),
                'playerHeightMin'           => $pool->getPlayerHeightMin(),
                'playerHeightMax'           => $pool->getPlayerHeightMax(),
                'playerWeightMin'           => $pool->getPlayerWeightMin(),
                'playerWeightMax'           => $pool->getPlayerWeightMax(),
                'personalityTraitMin'       => $pool->getPersonalityTraitMin(),
                'personalityTraitMax'       => $pool->getPersonalityTraitMax(),
                'positionWeightGk'          => $pool->getPositionWeightGk(),
                'positionWeightDef'         => $pool->getPositionWeightDef(),
                'positionWeightMid'         => $pool->getPositionWeightMid(),
                'positionWeightAtt'         => $pool->getPositionWeightAtt(),
                'coachAgeMin'               => $pool->getCoachAgeMin(),
                'coachAgeMax'               => $pool->getCoachAgeMax(),
                'coachAbilityMin'           => $pool->getCoachAbilityMin(),
                'coachAbilityMax'           => $pool->getCoachAbilityMax(),
                'scoutAgeMin'               => $pool->getScoutAgeMin(),
                'scoutAgeMax'               => $pool->getScoutAgeMax(),
                'scoutExperienceMin'        => $pool->getScoutExperienceMin(),
                'scoutExperienceMax'        => $pool->getScoutExperienceMax(),
                'scoutJudgementMin'         => $pool->getScoutJudgementMin(),
                'scoutJudgementMax'         => $pool->getScoutJudgementMax(),
                'agentReputationMin'        => $pool->getAgentReputationMin(),
                'agentReputationMax'        => $pool->getAgentReputationMax(),
                'agentAgeMin'               => $pool->getAgentAgeMin(),
                'agentAgeMax'               => $pool->getAgentAgeMax(),
                'playerPoolTarget'          => $pool->getPlayerPoolTarget(),
                'coachPoolTarget'           => $pool->getCoachPoolTarget(),
                'scoutPoolTarget'           => $pool->getScoutPoolTarget(),
                'sponsorPoolTarget'         => $pool->getSponsorPoolTarget(),
                'investorPoolTarget'        => $pool->getInvestorPoolTarget(),
                'agentPoolTarget'           => $pool->getAgentPoolTarget(),
            ],
        ];
    }

    // ── Import ────────────────────────────────────────────────────────────────

    /**
     * @return array{applied: bool, errors: string[]}
     */
    public function import(array $data): array
    {
        $result = ['applied' => false, 'errors' => []];

        if (($data['version'] ?? null) !== self::EXPORT_VERSION) {
            $result['errors'][] = 'Unsupported export version — expected version ' . self::EXPORT_VERSION;
            return $result;
        }

        try {
            if (isset($data['gameConfig'])) {
                $this->applyGameConfig($data['gameConfig']);
            }
            if (isset($data['starterConfig'])) {
                $this->applyStarterConfig($data['starterConfig']);
            }
            if (isset($data['poolConfig'])) {
                $this->applyPoolConfig($data['poolConfig']);
            }
            $this->em->flush();
            $result['applied'] = true;
        } catch (\Throwable $e) {
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    private function applyGameConfig(array $row): void
    {
        $config = $this->gameConfigRepository->getConfig();

        if (isset($row['cliqueRelationshipThreshold']))              $config->setCliqueRelationshipThreshold((int) $row['cliqueRelationshipThreshold']);
        if (isset($row['cliqueSquadCapPercent']))                    $config->setCliqueSquadCapPercent((int) $row['cliqueSquadCapPercent']);
        if (isset($row['cliqueMinTenureWeeks']))                     $config->setCliqueMinTenureWeeks((int) $row['cliqueMinTenureWeeks']);
        if (isset($row['baseXP']))                                   $config->setBaseXP((int) $row['baseXP']);
        if (isset($row['baseInjuryProbability']))                    $config->setBaseInjuryProbability((float) $row['baseInjuryProbability']);
        if (isset($row['regressionUpperThreshold']))                 $config->setRegressionUpperThreshold((int) $row['regressionUpperThreshold']);
        if (isset($row['regressionLowerThreshold']))                 $config->setRegressionLowerThreshold((int) $row['regressionLowerThreshold']);
        if (isset($row['reputationDeltaBase']))                      $config->setReputationDeltaBase((float) $row['reputationDeltaBase']);
        if (isset($row['reputationDeltaFacilityMultiplier']))        $config->setReputationDeltaFacilityMultiplier((float) $row['reputationDeltaFacilityMultiplier']);
        if (isset($row['injuryMinorWeight']))                        $config->setInjuryMinorWeight((int) $row['injuryMinorWeight']);
        if (isset($row['injuryModerateWeight']))                     $config->setInjuryModerateWeight((int) $row['injuryModerateWeight']);
        if (isset($row['injurySeriousWeight']))                      $config->setInjurySeriousWeight((int) $row['injurySeriousWeight']);
        if (isset($row['scoutMoraleThreshold']))                     $config->setScoutMoraleThreshold((int) $row['scoutMoraleThreshold']);
        if (isset($row['scoutRevealWeeks']))                         $config->setScoutRevealWeeks((int) $row['scoutRevealWeeks']);
        if (isset($row['scoutAbilityErrorRange']))                   $config->setScoutAbilityErrorRange((int) $row['scoutAbilityErrorRange']);
        if (isset($row['scoutMaxAssignments']))                      $config->setScoutMaxAssignments((int) $row['scoutMaxAssignments']);
        if (isset($row['missionGemRollThresholds']))                 $config->setMissionGemRollThresholds((array) $row['missionGemRollThresholds']);
        if (isset($row['playerFeeMultiplier']))                      $config->setPlayerFeeMultiplier((float) $row['playerFeeMultiplier']);
        if (isset($row['defaultMoraleMin']))                         $config->setDefaultMoraleMin((int) $row['defaultMoraleMin']);
        if (isset($row['defaultMoraleMax']))                         $config->setDefaultMoraleMax((int) $row['defaultMoraleMax']);
        if (isset($row['incidentLowProfessionalismThreshold']))      $config->setIncidentLowProfessionalismThreshold((int) $row['incidentLowProfessionalismThreshold']);
        if (isset($row['incidentLowProfessionalismChance']))         $config->setIncidentLowProfessionalismChance((float) $row['incidentLowProfessionalismChance']);
        if (isset($row['incidentHighDeterminationThreshold']))       $config->setIncidentHighDeterminationThreshold((int) $row['incidentHighDeterminationThreshold']);
        if (isset($row['incidentHighDeterminationChance']))          $config->setIncidentHighDeterminationChance((float) $row['incidentHighDeterminationChance']);
        if (isset($row['incidentAltercationBaseChance']))            $config->setIncidentAltercationBaseChance((float) $row['incidentAltercationBaseChance']);
        if (isset($row['incidentAltercationSeriousBase']))           $config->setIncidentAltercationSeriousBase((float) $row['incidentAltercationSeriousBase']);
        if (isset($row['incidentAltercationSeriousTemperamentScale'])) $config->setIncidentAltercationSeriousTemperamentScale((float) $row['incidentAltercationSeriousTemperamentScale']);
        if (isset($row['guardianConvinceMoraleBoost']))              $config->setGuardianConvinceMoraleBoost((int) $row['guardianConvinceMoraleBoost']);
        if (isset($row['guardianConvinceGuardianLoyaltyBoost']))     $config->setGuardianConvinceGuardianLoyaltyBoost((int) $row['guardianConvinceGuardianLoyaltyBoost']);
        if (isset($row['guardianConvinceGuardianDemandIncrease']))   $config->setGuardianConvinceGuardianDemandIncrease((int) $row['guardianConvinceGuardianDemandIncrease']);
        if (isset($row['guardianIgnoreMoralePenalty']))              $config->setGuardianIgnoreMoralePenalty((int) $row['guardianIgnoreMoralePenalty']);
        if (isset($row['guardianIgnoreLoyaltyTraitPenalty']))        $config->setGuardianIgnoreLoyaltyTraitPenalty((int) $row['guardianIgnoreLoyaltyTraitPenalty']);
        if (isset($row['guardianIgnoreGuardianLoyaltyPenalty']))     $config->setGuardianIgnoreGuardianLoyaltyPenalty((int) $row['guardianIgnoreGuardianLoyaltyPenalty']);
        if (isset($row['guardianIgnoreGuardianDemandIncrease']))     $config->setGuardianIgnoreGuardianDemandIncrease((int) $row['guardianIgnoreGuardianDemandIncrease']);
        if (isset($row['guardianIgnoreSiblingMoralePenalty']))       $config->setGuardianIgnoreSiblingMoralePenalty((int) $row['guardianIgnoreSiblingMoralePenalty']);
        if (isset($row['guardianIgnoreSiblingLoyaltyTraitPenalty'])) $config->setGuardianIgnoreSiblingLoyaltyTraitPenalty((int) $row['guardianIgnoreSiblingLoyaltyTraitPenalty']);
        if (array_key_exists('debugLoggingEnabled', $row))          $config->setDebugLoggingEnabled((bool) $row['debugLoggingEnabled']);
    }

    private function applyStarterConfig(array $row): void
    {
        $config = $this->starterConfigRepository->getConfig();

        if (isset($row['startingBalance']))    $config->setStartingBalance((int) $row['startingBalance']);
        if (isset($row['starterPlayerCount'])) $config->setStarterPlayerCount((int) $row['starterPlayerCount']);
        if (isset($row['starterCoachCount']))  $config->setStarterCoachCount((int) $row['starterCoachCount']);
        if (isset($row['starterScoutCount']))  $config->setStarterScoutCount((int) $row['starterScoutCount']);
        if (isset($row['starterSponsorTier'])) $config->setStarterSponsorTier((string) $row['starterSponsorTier']);
        if (isset($row['starterAcademyTier'])) $config->setStarterAcademyTier((string) $row['starterAcademyTier']);
    }

    private function applyPoolConfig(array $row): void
    {
        $config = $this->poolConfigRepository->getConfig();

        if (isset($row['playerAgeMin']))             $config->setPlayerAgeMin((int) $row['playerAgeMin']);
        if (isset($row['playerAgeMax']))             $config->setPlayerAgeMax((int) $row['playerAgeMax']);
        if (isset($row['playerPotentialMin']))       $config->setPlayerPotentialMin((int) $row['playerPotentialMin']);
        if (isset($row['playerPotentialMax']))       $config->setPlayerPotentialMax((int) $row['playerPotentialMax']);
        if (isset($row['playerPotentialMean']))      $config->setPlayerPotentialMean((int) $row['playerPotentialMean']);
        if (isset($row['playerAbilityMin']))         $config->setPlayerAbilityMin((int) $row['playerAbilityMin']);
        if (isset($row['playerAbilityMax']))         $config->setPlayerAbilityMax((int) $row['playerAbilityMax']);
        if (isset($row['playerAttributeBudgetMin'])) $config->setPlayerAttributeBudgetMin((int) $row['playerAttributeBudgetMin']);
        if (isset($row['playerAttributeBudgetMax'])) $config->setPlayerAttributeBudgetMax((int) $row['playerAttributeBudgetMax']);
        if (isset($row['playerAgentChancePercent'])) $config->setPlayerAgentChancePercent((int) $row['playerAgentChancePercent']);
        if (isset($row['playerHeightMin']))          $config->setPlayerHeightMin((int) $row['playerHeightMin']);
        if (isset($row['playerHeightMax']))          $config->setPlayerHeightMax((int) $row['playerHeightMax']);
        if (isset($row['playerWeightMin']))          $config->setPlayerWeightMin((int) $row['playerWeightMin']);
        if (isset($row['playerWeightMax']))          $config->setPlayerWeightMax((int) $row['playerWeightMax']);
        if (isset($row['personalityTraitMin']))      $config->setPersonalityTraitMin((int) $row['personalityTraitMin']);
        if (isset($row['personalityTraitMax']))      $config->setPersonalityTraitMax((int) $row['personalityTraitMax']);
        if (isset($row['positionWeightGk']))         $config->setPositionWeightGk((int) $row['positionWeightGk']);
        if (isset($row['positionWeightDef']))        $config->setPositionWeightDef((int) $row['positionWeightDef']);
        if (isset($row['positionWeightMid']))        $config->setPositionWeightMid((int) $row['positionWeightMid']);
        if (isset($row['positionWeightAtt']))        $config->setPositionWeightAtt((int) $row['positionWeightAtt']);
        if (isset($row['coachAgeMin']))              $config->setCoachAgeMin((int) $row['coachAgeMin']);
        if (isset($row['coachAgeMax']))              $config->setCoachAgeMax((int) $row['coachAgeMax']);
        if (isset($row['coachAbilityMin']))          $config->setCoachAbilityMin((int) $row['coachAbilityMin']);
        if (isset($row['coachAbilityMax']))          $config->setCoachAbilityMax((int) $row['coachAbilityMax']);
        if (isset($row['scoutAgeMin']))              $config->setScoutAgeMin((int) $row['scoutAgeMin']);
        if (isset($row['scoutAgeMax']))              $config->setScoutAgeMax((int) $row['scoutAgeMax']);
        if (isset($row['scoutExperienceMin']))       $config->setScoutExperienceMin((int) $row['scoutExperienceMin']);
        if (isset($row['scoutExperienceMax']))       $config->setScoutExperienceMax((int) $row['scoutExperienceMax']);
        if (isset($row['scoutJudgementMin']))        $config->setScoutJudgementMin((int) $row['scoutJudgementMin']);
        if (isset($row['scoutJudgementMax']))        $config->setScoutJudgementMax((int) $row['scoutJudgementMax']);
        if (isset($row['agentReputationMin']))       $config->setAgentReputationMin((int) $row['agentReputationMin']);
        if (isset($row['agentReputationMax']))       $config->setAgentReputationMax((int) $row['agentReputationMax']);
        if (isset($row['agentAgeMin']))              $config->setAgentAgeMin((int) $row['agentAgeMin']);
        if (isset($row['agentAgeMax']))              $config->setAgentAgeMax((int) $row['agentAgeMax']);
        if (isset($row['playerPoolTarget']))         $config->setPlayerPoolTarget((int) $row['playerPoolTarget']);
        if (isset($row['coachPoolTarget']))          $config->setCoachPoolTarget((int) $row['coachPoolTarget']);
        if (isset($row['scoutPoolTarget']))          $config->setScoutPoolTarget((int) $row['scoutPoolTarget']);
        if (isset($row['sponsorPoolTarget']))        $config->setSponsorPoolTarget((int) $row['sponsorPoolTarget']);
        if (isset($row['investorPoolTarget']))       $config->setInvestorPoolTarget((int) $row['investorPoolTarget']);
        if (isset($row['agentPoolTarget']))          $config->setAgentPoolTarget((int) $row['agentPoolTarget']);
    }
}
