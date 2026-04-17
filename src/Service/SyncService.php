<?php

namespace App\Service;

use App\Dto\MatchResultDto;
use App\Dto\SyncRequest;
use App\Entity\Club;
use App\Entity\GameConfig;
use App\Entity\MatchResult;
use App\Entity\NpcClub;
use App\Entity\Player;
use App\Entity\SyncRecord;
use App\Entity\TacticalAdvantage;
use App\Entity\Transfer;
use App\Entity\User;
use App\Enum\LeaderboardCategory;
use App\Enum\PlayerStatus;
use App\Enum\TransferType;
use App\Repository\ClubRepository;
use App\Repository\FacilityTemplateRepository;
use App\Repository\GameConfigRepository;
use App\Repository\LeaderboardEntryRepository;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;
use App\Repository\TacticalAdvantageRepository;
use Doctrine\ORM\EntityManagerInterface;

class SyncService
{
    public function __construct(
        private readonly ClubRepository              $clubRepository,
        private readonly LeaderboardEntryRepository     $leaderboardEntryRepository,
        private readonly EntityManagerInterface         $em,
        private readonly EconomicService                $economicService,
        private readonly InboxService                   $inboxService,
        private readonly GameConfigRepository           $gameConfigRepository,
        private readonly FacilityTemplateRepository     $facilityTemplateRepository,
        private readonly NpcClubRepository              $npcClubRepository,
        private readonly LeagueRepository               $leagueRepository,
        private readonly TacticalAdvantageRepository    $tacticalAdvantageRepository,
    ) {}

    /**
     * @return array{accepted: bool, weekNumber?: int, syncedAt?: string, club?: array, reason?: string, currentWeek?: int}
     */
    public function process(User $user, SyncRequest $request): array
    {
        $club = $this->clubRepository->findByUser($user);
        if ($club === null) {
            throw new \RuntimeException('Club not found for user.');
        }

        $clientTimestamp = new \DateTimeImmutable($request->clientTimestamp);

        // Record every sync attempt for audit and anti-cheat review.
        $syncRecord = new SyncRecord(
            $club,
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
        if ($request->weekNumber < $club->getLastSyncedWeek()) {
            $syncRecord->markInvalid('week_rollback');
            $this->em->flush();

            return [
                'accepted'    => false,
                'reason'      => 'week_rollback',
                'currentWeek' => $club->getLastSyncedWeek(),
            ];
        }

        // ── Club state update (fat-client authoritative) ───────────────────
        // The client is the game engine. balance, totalCareerEarnings, and
        // reputation are accepted as authoritative snapshots from the device.
        // The server does NOT recalculate wages or sponsor payments — those
        // already run on-device and are reflected in the incoming balance.

        $club->setBalance($request->balance);
        $club->setTotalCareerEarnings($request->totalCareerEarnings);
        $club->setReputation(max(0, (int) round($request->reputation)));

        // hallOfFamePoints never decreases — server keeps the high-water mark.
        $club->setHallOfFamePoints(
            max($club->getHallOfFamePoints(), (int) round($request->hallOfFamePoints))
        );

        $club->setLastSyncedWeek($request->weekNumber);
        $club->setLastSyncedAt(new \DateTimeImmutable());

        // Apply optional manager personality shifts (field may be absent in newer clients).
        if (!empty($request->managerShifts)) {
            $this->applyManagerShifts($club, $request->managerShifts);
        }

        // ── Leaderboard upserts ───────────────────────────────────────────────
        $isoWeek = (new \DateTimeImmutable())->format('o-\WW');

        foreach ([LeaderboardCategory::CAREER_EARNINGS, LeaderboardCategory::ACADEMY_REPUTATION, LeaderboardCategory::HALL_OF_FAME] as $category) {
            $score = match ($category) {
                LeaderboardCategory::CAREER_EARNINGS    => $club->getTotalCareerEarnings(),
                LeaderboardCategory::ACADEMY_REPUTATION => $club->getReputation(),
                LeaderboardCategory::HALL_OF_FAME       => $club->getHallOfFamePoints(),
            };

            foreach (['all-time', $isoWeek] as $period) {
                $entry = $this->leaderboardEntryRepository->findOrCreate($club, $category, $period);
                $entry->setScore($score);
            }
        }

        // ── Economic lifecycle checks ─────────────────────────────────────────
        if ($club->isFinancialYearEnd($request->weekNumber)) {
            $this->economicService->processFinancialYearEnd($club);
        }

        $this->economicService->checkSponsorContracts($club, $club->getReputation());
        $this->economicService->checkAgeOutPlayers($club, $request->weekNumber, $clientTimestamp);

        // ── Player attribute snapshots ────────────────────────────────────────
        if (!empty($request->players)) {
            $this->processPlayerUpdates($club, $request->players);
        }

        // ── Match result persistence ──────────────────────────────────────────
        if (!empty($request->matchResults)) {
            $this->processMatchResults($club, $request->matchResults);
        }

        $this->em->flush();

        // Auto-assign league on first sync if club has a country but no league.
        $this->maybeAutoAssignLeague($club);
        if ($club->getCurrentLeague() !== null) {
            $this->em->flush();
        }

        $syncedAt = $club->getLastSyncedAt();

        // Fetch runtime config to embed in response. Read-only: if no row exists
        // getConfig() always returns the singleton row (creating with defaults if absent),
        // so the ternary fallback below is now only a safety net for truly unexpected nulls.
        $gameConfig = $this->gameConfigRepository->getConfig();

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
            'debugLoggingEnabled'                        => $gameConfig->isDebugLoggingEnabled(),
            // League finances
            'smallSponsorMin'               => $gameConfig->getSmallSponsorMin(),
            'smallSponsorMax'               => $gameConfig->getSmallSponsorMax(),
            'mediumSponsorMin'              => $gameConfig->getMediumSponsorMin(),
            'mediumSponsorMax'              => $gameConfig->getMediumSponsorMax(),
            'largeSponsorMin'               => $gameConfig->getLargeSponsorMin(),
            'largeSponsorMax'               => $gameConfig->getLargeSponsorMax(),
            'leaguePositionDecreasePercent' => $gameConfig->getLeaguePositionDecreasePercent(),
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
            'debugLoggingEnabled'                        => false,
            // League finances
            'smallSponsorMin'               => 0,
            'smallSponsorMax'               => 0,
            'mediumSponsorMin'              => 0,
            'mediumSponsorMax'              => 0,
            'largeSponsorMin'               => 0,
            'largeSponsorMax'               => 0,
            'leaguePositionDecreasePercent' => 5,
        ];

        $facilityTemplates = array_map(
            fn ($t) => $t->toArray(),
            $this->facilityTemplateRepository->getActiveTemplates(),
        );

        $tacticalMatrix = array_map(fn (TacticalAdvantage $ta) => [
            'style'         => $ta->getStyle()->value,
            'opponentStyle' => $ta->getOpponentStyle()->value,
            'multiplier'    => $ta->getMultiplier(),
        ], $this->tacticalAdvantageRepository->findAll());

        $gameConfigData['tacticalMatrix'] = $tacticalMatrix;

        return [
            'accepted'          => true,
            'weekNumber'        => $request->weekNumber,
            'syncedAt'          => $syncedAt->format(\DateTimeInterface::ATOM),
            'facilityTemplates' => $facilityTemplates,
            'club'           => [
                'id'                  => (string) $club->getId(),
                'reputation'          => $club->getReputation(),
                'totalCareerEarnings' => $club->getTotalCareerEarnings(),
                'hallOfFamePoints'    => $club->getHallOfFamePoints(),
                'balance'             => $club->getBalance(),
                'hasDebt'             => $club->hasDebt(),
                'formation'           => $club->getFormation()->value,
                'manager'             => [
                    'temperament' => $club->getManagerTemperament(),
                    'discipline'  => $club->getManagerDiscipline(),
                    'ambition'    => $club->getManagerAmbition(),
                ],
            ],
            'gameConfig' => $gameConfigData,
            'league'     => $this->buildLeagueSnapshot($club, $gameConfig),
        ];
    }

    /**
     * Applies incremental manager personality trait shifts sent by the client.
     * Each trait is clamped to [0, 100] by the Club setters.
     *
     * @param array<string, int> $shifts  e.g. ['temperament' => 2, 'discipline' => -1]
     */
    private function applyManagerShifts(Club $club, array $shifts): void
    {
        if (isset($shifts['temperament'])) {
            $club->setManagerTemperament($club->getManagerTemperament() + (int) $shifts['temperament']);
        }
        if (isset($shifts['discipline'])) {
            $club->setManagerDiscipline($club->getManagerDiscipline() + (int) $shifts['discipline']);
        }
        if (isset($shifts['ambition'])) {
            $club->setManagerAmbition($club->getManagerAmbition() + (int) $shifts['ambition']);
        }
    }

    /**
     * Apply player attribute snapshots from the client (fat-client authoritative).
     * Only updates players that belong to the syncing club.
     */
    private function processPlayerUpdates(Club $club, array $players): void
    {
        foreach ($players as $data) {
            if (empty($data['playerId'])) {
                continue;
            }

            $player = $this->em->getRepository(Player::class)->find($data['playerId']);
            if ($player === null || $player->getClub() !== $club) {
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

    /**
     * Persists MatchResult entities from sync payload.
     *
     * @param MatchResultDto[] $results
     */
    private function processMatchResults(Club $club, array $results): void
    {
        foreach ($results as $dto) {
            if (empty($dto->opponentClubId)) {
                continue;
            }
            $opponent = $this->npcClubRepository->find($dto->opponentClubId);
            if ($opponent === null) {
                continue; // silently skip unknown clubs
            }
            $matchResult = new MatchResult(
                club:         $club,
                opponentClub: $opponent,
                goalsFor:     $dto->goalsFor,
                goalsAgainst: $dto->goalsAgainst,
                week:         $dto->week,
                season:       $club->getCurrentSeason(),
            );
            $this->em->persist($matchResult);
        }
    }

    /**
     * One-time auto-assignment: if the club has no current league and has a country set,
     * assign the lowest-tier league for that country.
     * No-op on all subsequent syncs once league is assigned.
     */
    private function maybeAutoAssignLeague(Club $club): void
    {
        if ($club->getCurrentLeague() !== null) {
            return;
        }

        if ($club->getCountry() === null) {
            return;
        }

        $league = $this->leagueRepository->findLowestTierForCountry($club->getCountry());
        if ($league === null) {
            return;
        }

        $club->setCurrentLeague($league);
        $this->em->persist($club);
    }

    /**
     * Builds the league snapshot array for the sync response.
     * Returns null if the club has no current league.
     */
    private function buildLeagueSnapshot(Club $club, GameConfig $gameConfig): ?array
    {
        $league = $club->getCurrentLeague();
        if ($league === null) {
            return null;
        }

        $clubs = $this->npcClubRepository->findByLeague($league);

        $sponsorPot = 0;
        foreach ($league->getLeagueSponsors() as $ls) {
            $sponsorPot += (int) $ls->getRolledValue();
        }

        return [
            'id'                            => (string) $league->getId(),
            'tier'                          => $league->getTier(),
            'name'                          => $league->getName(),
            'country'                       => $league->getCountry(),
            'season'                        => $club->getCurrentSeason(),
            'promotionSpots'                => $league->getPromotionSpots(),
            'reputationTier'                => $league->getLeagueReputationTier()?->value,
            'tvDeal'                        => $league->getTvDeal() !== null ? (int) $league->getTvDeal() : null,
            'sponsorPot'                    => (int) $sponsorPot,
            'prizeMoney'                    => $league->getPrizeMoney() !== null ? (int) $league->getPrizeMoney() : null,
            'leaguePositionPot'             => $league->getLeaguePositionPot() !== null ? (int) $league->getLeaguePositionPot() : null,
            'leaguePositionDecreasePercent' => $gameConfig->getLeaguePositionDecreasePercent(),
            'clubs'                         => array_map(fn(NpcClub $c) => [
                'id'             => (string) $c->getId(),
                'name'           => $c->getName(),
                'reputation'     => $c->getReputation(),
                'tier'           => $c->getTier(),
                'primaryColor'   => $c->getPrimaryColor(),
                'secondaryColor' => $c->getSecondaryColor(),
                'stadiumName'    => $c->getStadiumName(),
                'facilities'     => $c->getFacilities(),
                'formation'      => $c->getFormation()->value,
                'personality'    => [
                    'playingStyle'       => $c->getPlayingStyle(),
                    'financialApproach'  => $c->getFinancialApproach(),
                    'managerTemperament' => $c->getManagerTemperament(),
                ],
            ], $clubs),
        ];
    }

    private function processTransfers(Club $club, array $transfers, \DateTimeImmutable $syncedAt): void
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
                $club,
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

            if ($player !== null && $player->getClub() === $club) {
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
