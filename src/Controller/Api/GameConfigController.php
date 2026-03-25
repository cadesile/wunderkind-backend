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
        ]);
    }
}
