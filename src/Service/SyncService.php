<?php

namespace App\Service;

use App\Dto\SyncRequest;
use App\Entity\Player;
use App\Entity\SyncRecord;
use App\Entity\Transfer;
use App\Entity\User;
use App\Enum\LeaderboardCategory;
use App\Enum\PlayerStatus;
use App\Enum\TransferType;
use App\Entity\Academy;
use App\Repository\AcademyRepository;
use App\Repository\GameConfigRepository;
use App\Repository\LeaderboardEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

class SyncService
{
    public function __construct(
        private readonly AcademyRepository          $academyRepository,
        private readonly LeaderboardEntryRepository $leaderboardEntryRepository,
        private readonly EntityManagerInterface     $em,
        private readonly EconomicService            $economicService,
        private readonly InboxService               $inboxService,
        private readonly GameConfigRepository       $gameConfigRepository,
    ) {}

    /**
     * @return array{accepted: bool, weekNumber?: int, syncedAt?: string, academy?: array, reason?: string, currentWeek?: int}
     */
    public function process(User $user, SyncRequest $request): array
    {
        $academy = $this->academyRepository->findByUser($user);
        if ($academy === null) {
            throw new \RuntimeException('Academy not found for user.');
        }

        $clientTimestamp = new \DateTimeImmutable($request->clientTimestamp);

        // Record every sync attempt for audit and anti-cheat review.
        $syncRecord = new SyncRecord(
            $academy,
            $request->weekNumber,
            $clientTimestamp,
            [
                'earningsDelta'       => $request->earningsDelta,
                'balance'             => $request->balance,
                'totalCareerEarnings' => $request->totalCareerEarnings,
                'reputationDelta'     => $request->reputationDelta,
                'reputation'          => $request->reputation,
                'hallOfFamePoints'    => $request->hallOfFamePoints,
                'squadSize'           => $request->squadSize,
                'staffCount'          => $request->staffCount,
                'facilityLevels'      => $request->facilityLevels,
                'managerShifts'       => $request->managerShifts,
                'transfers'           => array_map(fn($t) => [
                    'playerId'        => $t->playerId,
                    'playerName'      => $t->playerName,
                    'destinationClub' => $t->destinationClub,
                    'grossFee'        => $t->grossFee,
                    'agentCommission' => $t->agentCommission,
                    'netProceeds'     => $t->netProceeds,
                    'type'            => $t->type,
                ], $request->transfers),
                'ledger'              => array_map(fn($e) => [
                    'category'    => $e->category,
                    'amount'      => $e->amount,
                    'description' => $e->description,
                ], $request->ledger),
            ],
        );
        $this->em->persist($syncRecord);

        // Anti-cheat: reject week rollbacks.
        if ($request->weekNumber < $academy->getLastSyncedWeek()) {
            $syncRecord->markInvalid('week_rollback');
            $this->em->flush();

            return [
                'accepted'    => false,
                'reason'      => 'week_rollback',
                'currentWeek' => $academy->getLastSyncedWeek(),
            ];
        }

        // ── Academy state update (fat-client authoritative) ───────────────────
        // The client is the game engine. balance, totalCareerEarnings, and
        // reputation are accepted as authoritative snapshots from the device.
        // The server does NOT recalculate wages or sponsor payments — those
        // already run on-device and are reflected in the incoming balance.

        $academy->setBalance($request->balance);
        $academy->setTotalCareerEarnings($request->totalCareerEarnings);
        $academy->setReputation(max(0, (int) round($request->reputation)));

        // hallOfFamePoints never decreases — server keeps the high-water mark.
        $academy->setHallOfFamePoints(
            max($academy->getHallOfFamePoints(), (int) round($request->hallOfFamePoints))
        );

        $academy->setLastSyncedWeek($request->weekNumber);
        $academy->setLastSyncedAt(new \DateTimeImmutable());

        // Apply optional manager personality shifts (field may be absent in newer clients).
        if (!empty($request->managerShifts)) {
            $this->applyManagerShifts($academy, $request->managerShifts);
        }

        // ── Leaderboard upserts ───────────────────────────────────────────────
        $isoWeek = (new \DateTimeImmutable())->format('o-\WW');

        foreach ([LeaderboardCategory::CAREER_EARNINGS, LeaderboardCategory::ACADEMY_REPUTATION, LeaderboardCategory::HALL_OF_FAME] as $category) {
            $score = match ($category) {
                LeaderboardCategory::CAREER_EARNINGS    => $academy->getTotalCareerEarnings(),
                LeaderboardCategory::ACADEMY_REPUTATION => $academy->getReputation(),
                LeaderboardCategory::HALL_OF_FAME       => $academy->getHallOfFamePoints(),
            };

            foreach (['all-time', $isoWeek] as $period) {
                $entry = $this->leaderboardEntryRepository->findOrCreate($academy, $category, $period);
                $entry->setScore($score);
            }
        }

        // ── Economic lifecycle checks ─────────────────────────────────────────
        if ($academy->isFinancialYearEnd($request->weekNumber)) {
            $this->economicService->processFinancialYearEnd($academy);
        }

        $this->economicService->checkSponsorContracts($academy, $academy->getReputation());
        $this->economicService->checkAgeOutPlayers($academy, $request->weekNumber, $clientTimestamp);

        // ── Player attribute snapshots ────────────────────────────────────────
        if (!empty($request->players)) {
            $this->processPlayerUpdates($academy, $request->players);
        }

        $this->em->flush();

        $syncedAt = $academy->getLastSyncedAt();

        // Fetch runtime config to embed in response. Read-only: if no row exists
        // yet (fresh install before seeder runs) fall back to hardcoded defaults
        // without persisting anything.
        $gameConfig = $this->gameConfigRepository->find(1);

        $gameConfigData = $gameConfig !== null ? [
            'cliqueRelationshipThreshold'                => $gameConfig->getCliqueRelationshipThreshold(),
            'cliqueSquadCapPercent'                      => $gameConfig->getCliqueSquadCapPercent(),
            'cliqueMinTenureWeeks'                       => $gameConfig->getCliqueMinTenureWeeks(),
            'baseXP'                                     => $gameConfig->getBaseXP(),
            'baseInjuryProbability'                      => $gameConfig->getBaseInjuryProbability(),
            'regressionUpperThreshold'                   => $gameConfig->getRegressionUpperThreshold(),
            'regressionLowerThreshold'                   => $gameConfig->getRegressionLowerThreshold(),
            'reputationDeltaBase'                        => $gameConfig->getReputationDeltaBase(),
            'reputationDeltaFacilityMultiplier'          => $gameConfig->getReputationDeltaFacilityMultiplier(),
            'injuryMinorWeight'                          => $gameConfig->getInjuryMinorWeight(),
            'injuryModerateWeight'                       => $gameConfig->getInjuryModerateWeight(),
            'injurySeriousWeight'                        => $gameConfig->getInjurySeriousWeight(),
            'scoutMoraleThreshold'                       => $gameConfig->getScoutMoraleThreshold(),
            'scoutRevealWeeks'                           => $gameConfig->getScoutRevealWeeks(),
            'scoutAbilityErrorRange'                     => $gameConfig->getScoutAbilityErrorRange(),
            'scoutMaxAssignments'                        => $gameConfig->getScoutMaxAssignments(),
            'missionGemRollThresholds'                   => $gameConfig->getMissionGemRollThresholds(),
            'playerFeeMultiplier'                        => $gameConfig->getPlayerFeeMultiplier(),
            'defaultMoraleMin'                           => $gameConfig->getDefaultMoraleMin(),
            'defaultMoraleMax'                           => $gameConfig->getDefaultMoraleMax(),
            'incidentLowProfessionalismThreshold'        => $gameConfig->getIncidentLowProfessionalismThreshold(),
            'incidentLowProfessionalismChance'           => $gameConfig->getIncidentLowProfessionalismChance(),
            'incidentHighDeterminationThreshold'         => $gameConfig->getIncidentHighDeterminationThreshold(),
            'incidentHighDeterminationChance'            => $gameConfig->getIncidentHighDeterminationChance(),
            'incidentAltercationBaseChance'              => $gameConfig->getIncidentAltercationBaseChance(),
            'incidentAltercationSeriousBase'             => $gameConfig->getIncidentAltercationSeriousBase(),
            'incidentAltercationSeriousTemperamentScale' => $gameConfig->getIncidentAltercationSeriousTemperamentScale(),
            'guardianConvinceMoraleBoost'                => $gameConfig->getGuardianConvinceMoraleBoost(),
            'guardianConvinceGuardianLoyaltyBoost'       => $gameConfig->getGuardianConvinceGuardianLoyaltyBoost(),
            'guardianConvinceGuardianDemandIncrease'     => $gameConfig->getGuardianConvinceGuardianDemandIncrease(),
            'guardianIgnoreMoralePenalty'                => $gameConfig->getGuardianIgnoreMoralePenalty(),
            'guardianIgnoreLoyaltyTraitPenalty'          => $gameConfig->getGuardianIgnoreLoyaltyTraitPenalty(),
            'guardianIgnoreGuardianLoyaltyPenalty'       => $gameConfig->getGuardianIgnoreGuardianLoyaltyPenalty(),
            'guardianIgnoreGuardianDemandIncrease'       => $gameConfig->getGuardianIgnoreGuardianDemandIncrease(),
            'guardianIgnoreSiblingMoralePenalty'         => $gameConfig->getGuardianIgnoreSiblingMoralePenalty(),
            'guardianIgnoreSiblingLoyaltyTraitPenalty'   => $gameConfig->getGuardianIgnoreSiblingLoyaltyTraitPenalty(),
        ] : [
            'cliqueRelationshipThreshold'                => 20,
            'cliqueSquadCapPercent'                      => 30,
            'cliqueMinTenureWeeks'                       => 3,
            'baseXP'                                     => 10,
            'baseInjuryProbability'                      => 0.05,
            'regressionUpperThreshold'                   => 14,
            'regressionLowerThreshold'                   => 7,
            'reputationDeltaBase'                        => 0.5,
            'reputationDeltaFacilityMultiplier'          => 1.2,
            'injuryMinorWeight'                          => 60,
            'injuryModerateWeight'                       => 30,
            'injurySeriousWeight'                        => 10,
            'scoutMoraleThreshold'                       => 40,
            'scoutRevealWeeks'                           => 2,
            'scoutAbilityErrorRange'                     => 30,
            'scoutMaxAssignments'                        => 5,
            'missionGemRollThresholds'                   => [0.25, 0.75, 0.85, 0.94],
            'playerFeeMultiplier'                        => 1000.0,
            'defaultMoraleMin'                           => 50,
            'defaultMoraleMax'                           => 80,
            'incidentLowProfessionalismThreshold'        => 6,
            'incidentLowProfessionalismChance'           => 0.3,
            'incidentHighDeterminationThreshold'         => 15,
            'incidentHighDeterminationChance'            => 0.25,
            'incidentAltercationBaseChance'              => 0.10,
            'incidentAltercationSeriousBase'             => 0.2,
            'incidentAltercationSeriousTemperamentScale' => 0.5,
            'guardianConvinceMoraleBoost'                => 5,
            'guardianConvinceGuardianLoyaltyBoost'       => 8,
            'guardianConvinceGuardianDemandIncrease'     => 1,
            'guardianIgnoreMoralePenalty'                => 8,
            'guardianIgnoreLoyaltyTraitPenalty'          => 3,
            'guardianIgnoreGuardianLoyaltyPenalty'       => 12,
            'guardianIgnoreGuardianDemandIncrease'       => 2,
            'guardianIgnoreSiblingMoralePenalty'         => 5,
            'guardianIgnoreSiblingLoyaltyTraitPenalty'   => 2,
        ];

        return [
            'accepted'   => true,
            'weekNumber' => $request->weekNumber,
            'syncedAt'   => $syncedAt->format(\DateTimeInterface::ATOM),
            'academy'    => [
                'reputation'          => $academy->getReputation(),
                'totalCareerEarnings' => $academy->getTotalCareerEarnings(),
                'hallOfFamePoints'    => $academy->getHallOfFamePoints(),
                'balance'             => $academy->getBalance(),
                'hasDebt'             => $academy->hasDebt(),
                'manager'             => [
                    'temperament' => $academy->getManagerTemperament(),
                    'discipline'  => $academy->getManagerDiscipline(),
                    'ambition'    => $academy->getManagerAmbition(),
                ],
            ],
            'gameConfig' => $gameConfigData,
        ];
    }

    /**
     * Applies incremental manager personality trait shifts sent by the client.
     * Each trait is clamped to [0, 100] by the Academy setters.
     *
     * @param array<string, int> $shifts  e.g. ['temperament' => 2, 'discipline' => -1]
     */
    private function applyManagerShifts(Academy $academy, array $shifts): void
    {
        if (isset($shifts['temperament'])) {
            $academy->setManagerTemperament($academy->getManagerTemperament() + (int) $shifts['temperament']);
        }
        if (isset($shifts['discipline'])) {
            $academy->setManagerDiscipline($academy->getManagerDiscipline() + (int) $shifts['discipline']);
        }
        if (isset($shifts['ambition'])) {
            $academy->setManagerAmbition($academy->getManagerAmbition() + (int) $shifts['ambition']);
        }
    }

    /**
     * Apply player attribute snapshots from the client (fat-client authoritative).
     * Only updates players that belong to the syncing academy.
     */
    private function processPlayerUpdates(Academy $academy, array $players): void
    {
        foreach ($players as $data) {
            if (empty($data['playerId'])) {
                continue;
            }

            $player = $this->em->getRepository(Player::class)->find($data['playerId']);
            if ($player === null || $player->getAcademy() !== $academy) {
                continue;
            }

            if (isset($data['pace']))      { $player->setPace((int) $data['pace']); }
            if (isset($data['technical'])) { $player->setTechnical((int) $data['technical']); }
            if (isset($data['vision']))    { $player->setVision((int) $data['vision']); }
            if (isset($data['power']))     { $player->setPower((int) $data['power']); }
            if (isset($data['stamina']))   { $player->setStamina((int) $data['stamina']); }
            if (isset($data['heart']))     { $player->setHeart((int) $data['heart']); }
            if (isset($data['height']))    { $player->setHeight((int) $data['height']); }
            if (isset($data['weight']))    { $player->setWeight((int) $data['weight']); }
            if (isset($data['morale']))    { $player->setMorale((int) $data['morale']); }
        }
    }

    private function processTransfers(Academy $academy, array $transfers, \DateTimeImmutable $syncedAt): void
    {
        foreach ($transfers as $data) {
            $player = null;
            if (!empty($data['playerId'])) {
                $player = $this->em->getRepository(Player::class)->find($data['playerId']);
            }

            $occurredAt = isset($data['occurredAt'])
                ? new \DateTimeImmutable($data['occurredAt'])
                : $syncedAt;

            $type = isset($data['type'])
                ? (TransferType::tryFrom($data['type']) ?? TransferType::SALE)
                : TransferType::SALE;

            $transfer = new Transfer(
                $player,
                $academy,
                $data['buyingClub'] ?? 'Unknown Club',
                $type,
                $occurredAt,
            );

            $transfer->setFee($data['transferFee'] ?? 0);
            $transfer->setAgentCommission($data['agentCommission'] ?? 0);
            $transfer->setNetProceeds($data['netProceeds'] ?? 0);
            $transfer->setDevelopmentPoints($data['developmentPoints'] ?? 0);
            $transfer->setReputationGained($data['reputationGained'] ?? 0);
            $transfer->setBuyingClub($data['buyingClub'] ?? null);
            $transfer->setSyncedAt($syncedAt);

            if ($player !== null && $player->getAcademy() === $academy) {
                $player->setStatus(
                    $type === TransferType::AGENT_ASSISTED
                        ? PlayerStatus::TRANSFERRED_VIA_AGENT
                        : PlayerStatus::TRANSFERRED
                );
            }

            $this->em->persist($transfer);
        }
    }
}
