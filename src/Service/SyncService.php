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
use App\Enum\StaffRole;
use App\Enum\TransferType;
use App\Repository\ClubFacilityRepository;
use App\Repository\ClubRepository;
use App\Repository\FacilityTemplateRepository;
use App\Repository\GameConfigRepository;
use App\Repository\LeaderboardEntryRepository;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;
use App\Repository\PlayerCareerStatRepository;
use App\Repository\SyncRecordRepository;
use App\Repository\TacticalAdvantageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

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
        private readonly SyncRecordRepository           $syncRecordRepository,
        private readonly ClubFacilityRepository         $clubFacilityRepository,
        private readonly PlayerCareerStatRepository     $playerCareerStatRepository,
        private readonly LoggerInterface                 $logger,
        private readonly FacilityImageResolver           $facilityImageResolver,
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
                // v2
                'squadAvgOvr'         => $request->squadAvgOvr,
                'form'                => $request->form,
                'leaguePosition'      => $request->leaguePosition,
                'seasonRecord'        => $request->seasonRecord,
                'playerStats'         => $request->playerStats,
                'signings'            => $request->signings,
                'transfers'           => array_map(fn($t) => [
                    'playerId'        => $t->playerId,
                    'playerName'      => $t->playerName,
                    'startingClub'    => $t->startingClub,
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
                'attendance'          => $request->attendance,
            ],
        );
        if ($request->log !== null) {
            $syncRecord->setDebugLog($request->log);
            $this->checkDebugLogThresholds($request->log);
        }
        $this->em->persist($syncRecord);

        // Captured before setLastSyncedWeek() below — the financial-year check needs the
        // span the client is reporting, not just its end week (syncs batch ~4 weeks).
        $previousSyncedWeek = $club->getLastSyncedWeek();

        // Rollback detection: client sent a week behind the server's last recorded week.
        // Accept the sync but flag it, then purge any superseded future records so
        // the rollback becomes the new canonical timeline for this club.
        if ($request->weekNumber < $club->getLastSyncedWeek()) {
            $syncRecord->markRollback();
            // Flush the new record first so the DELETE doesn't also remove it.
            $this->em->flush();
            $this->syncRecordRepository->deleteByClubFromWeek($club, $request->weekNumber + 1);
            $this->logger->info('sync.rollback', [
                'clubId'       => (string) $club->getId(),
                'fromWeek'     => $club->getLastSyncedWeek(),
                'toWeek'       => $request->weekNumber,
            ]);
        }

        // ── Club state update (fat-client authoritative) ───────────────────
        // The client is the game engine. balance, totalCareerEarnings, and
        // reputation are accepted as authoritative snapshots from the device.
        // The server does NOT recalculate wages or sponsor payments — those
        // already run on-device and are reflected in the incoming balance.

        $club->setBalance($request->balance);
        $club->setTotalCareerEarnings($request->totalCareerEarnings);
        $club->setReputation(max(0, (int) round($request->reputation)));

        // hallOfFamePoints is NOT taken from the client — it is derived server-side from
        // SeasonRecord titles by HallOfFameScoreService, refreshed on conclude-season and by
        // app:leaderboards:generate. The incoming value is still recorded in the SyncRecord
        // payload above for diagnostics.

        $club->setLastSyncedWeek($request->weekNumber);
        $club->setLastSyncedAt(new \DateTimeImmutable());

        // Apply optional manager personality shifts (field may be absent in newer clients).
        if (!empty($request->managerShifts)) {
            $this->applyManagerShifts($club, $request->managerShifts);
        }

        // Record tutorial completion exactly once — never overwrite an existing timestamp.
        if ($request->tutorialCompletedAt !== null && $club->getTutorialCompletedAt() === null) {
            try {
                $club->setTutorialCompletedAt(new \DateTimeImmutable($request->tutorialCompletedAt));
            } catch (\Exception) { /* malformed timestamp — ignore */ }
        }

        // Fan/attendance snapshot — backs the 'fanatics' leaderboard.
        $club->applyAttendanceSnapshot($request->attendance);

        // Facility levels — backs the 'empire_index' leaderboard (computed by LeaderboardCalculationService, not here).
        if (!empty($request->facilityLevels)) {
            $this->processFacilityLevels($club, $request->facilityLevels);
        }

        // Player season stats — back the 'golden_boot'/'playmaker' leaderboards (computed by LeaderboardCalculationService, not here).
        if (!empty($request->playerStats)) {
            $this->processPlayerCareerStats($club, $request->playerStats);
        }

        // ── Leaderboard upserts ───────────────────────────────────────────────
        // Only the "cheap" categories (plain Club scalar reads) are upserted on every
        // sync. empire_index/golden_boot/playmaker/hall_of_fame require cross-table aggregation
        // and are computed only by LeaderboardCalculationService (CLI command / cache-miss path).
        $isoWeek = (new \DateTimeImmutable())->format('o-\WW');

        $cheapCategories = [
            LeaderboardCategory::CAREER_EARNINGS,
            LeaderboardCategory::CLUB_REPUTATION,
            LeaderboardCategory::FANATICS,
        ];

        foreach ($cheapCategories as $category) {
            $score = match ($category) {
                LeaderboardCategory::CAREER_EARNINGS    => $club->getTotalCareerEarnings(),
                LeaderboardCategory::CLUB_REPUTATION => $club->getReputation(),
                LeaderboardCategory::FANATICS            => $club->getTotalSeasonAttendance(),
            };

            foreach (['all-time', $isoWeek] as $period) {
                $entry = $this->leaderboardEntryRepository->findOrCreate($club, $category, $period);
                $entry->setScore($score);
            }
        }

        // ── Economic lifecycle checks ─────────────────────────────────────────
        $dividendPaidPence = 0;
        if ($club->crossedFinancialYearEnd($previousSyncedWeek, $request->weekNumber)) {
            $yearEndResult     = $this->economicService->processFinancialYearEnd($club);
            $dividendPaidPence = $yearEndResult['dividendPaidPence'];
        }

        $this->economicService->checkSponsorContracts($club, $club->getReputation());

        // ── Player attribute snapshots ────────────────────────────────────────
        if (!empty($request->players)) {
            $this->processPlayerUpdates($club, $request->players);
        }

        // ── Outgoing transfers (players leaving the AMP club) ─────────────────
        if (!empty($request->transfers)) {
            $this->processOutgoingTransfers($club, $request->transfers, $clientTimestamp);
        }

        // ── Signings (players joining the AMP club) ───────────────────────────
        if (!empty($request->signings)) {
            $this->processSignings($club, $request->signings, $clientTimestamp);
        }

        // ── Match result persistence ──────────────────────────────────────────
        $syncedFixtureIds = [];
        if (!empty($request->matchResults)) {
            $syncedFixtureIds = $this->processMatchResults($club, $request->matchResults);
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
            // Sponsor & investor offer probabilities
            'sponsorProbabilityLocal'     => $gameConfig->getSponsorProbabilityLocal(),
            'sponsorProbabilityRegional'  => $gameConfig->getSponsorProbabilityRegional(),
            'sponsorProbabilityNational'  => $gameConfig->getSponsorProbabilityNational(),
            'sponsorProbabilityElite'     => $gameConfig->getSponsorProbabilityElite(),
            'investorProbabilityLocal'    => $gameConfig->getInvestorProbabilityLocal(),
            'investorProbabilityRegional' => $gameConfig->getInvestorProbabilityRegional(),
            'investorProbabilityNational' => $gameConfig->getInvestorProbabilityNational(),
            'investorProbabilityElite'    => $gameConfig->getInvestorProbabilityElite(),
            // Staff limits per club
            'maxCoachesPerClub'             => $gameConfig->getMaxCoachesPerClub(),
            'maxManagersPerClub'            => $gameConfig->getMaxManagersPerClub(),
            'maxDirectorsOfFootballPerClub' => $gameConfig->getMaxDirectorsOfFootballPerClub(),
            'maxFacilityManagersPerClub'    => $gameConfig->getMaxFacilityManagersPerClub(),
            'maxChairmensPerClub'           => $gameConfig->getMaxChairmensPerClub(),
            'maxScoutsPerClub'              => $gameConfig->getMaxScoutsPerClub(),
            'leaguePlayerAbilityRanges'     => $gameConfig->getLeaguePlayerAbilityRanges(),
            'managerDividendPercent'        => $gameConfig->getManagerDividendPercent(),
            'transferBudgetPercent'         => $gameConfig->getTransferBudgetPercent(),
            'seasonTicketHolderPercent'     => $gameConfig->getSeasonTicketHolderPercent(),
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
            // Sponsor & investor offer probabilities
            'sponsorProbabilityLocal'     => 0.30,
            'sponsorProbabilityRegional'  => 0.20,
            'sponsorProbabilityNational'  => 0.10,
            'sponsorProbabilityElite'     => 0.05,
            'investorProbabilityLocal'    => 0.20,
            'investorProbabilityRegional' => 0.12,
            'investorProbabilityNational' => 0.06,
            'investorProbabilityElite'    => 0.02,
            // Staff limits per club
            'maxCoachesPerClub'             => 15,
            'maxManagersPerClub'            => 1,
            'maxDirectorsOfFootballPerClub' => 1,
            'maxFacilityManagersPerClub'    => 1,
            'maxChairmensPerClub'           => 1,
            'maxScoutsPerClub'              => 3,
            'leaguePlayerAbilityRanges'     => [],
            'managerDividendPercent'        => 5.0,
            'transferBudgetPercent'         => 20.0,
            'seasonTicketHolderPercent'     => 60,
        ];

        $facilityTemplates = array_map(
            fn ($t) => [...$t->toArray(), 'images' => $this->facilityImageResolver->resolve($t->getSlug())],
            $this->facilityTemplateRepository->getActiveTemplates(),
        );

        $tacticalMatrix = array_map(fn (TacticalAdvantage $ta) => [
            'style'         => $ta->getStyle()->value,
            'opponentStyle' => $ta->getOpponentStyle()->value,
            'multiplier'    => $ta->getMultiplier(),
        ], $this->tacticalAdvantageRepository->findAll());

        $gameConfigData['tacticalMatrix'] = $tacticalMatrix;
        $gameConfigData['staffRoles'] = array_column(StaffRole::cases(), 'value');

        $achievements = $this->detectAchievements($request);

        return [
            'accepted'          => true,
            'isRollback'        => $syncRecord->isRollback(),
            'weekNumber'        => $request->weekNumber,
            'syncedAt'          => $syncedAt->format(\DateTimeInterface::ATOM),
            'facilityTemplates' => $facilityTemplates,
            'dividendPaidPence' => $dividendPaidPence,
            'club'              => [
                'id'                   => (string) $club->getId(),
                'reputation'           => $club->getReputation(),
                'totalCareerEarnings'  => $club->getTotalCareerEarnings(),
                'hallOfFamePoints'     => $club->getHallOfFamePoints(),
                'balance'              => $club->getBalance(),
                'hasDebt'              => $club->hasDebt(),
                'formation'            => $club->getFormation()->value,
                'tutorialCompletedAt'  => $club->getTutorialCompletedAt()?->format(\DateTimeInterface::ATOM),
                'manager'              => [
                    'temperament' => $club->getManagerTemperament(),
                    'discipline'  => $club->getManagerDiscipline(),
                    'ambition'    => $club->getManagerAmbition(),
                ],
            ],
            'gameConfig'        => $gameConfigData,
            'league'            => $this->buildLeagueSnapshot($club, $gameConfig),
            'syncedFixtureIds'  => $syncedFixtureIds,
            'achievements'      => $achievements,
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

            $player = $this->findPlayerByClientId($data['playerId']);
            if ($player === null) {
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
     * Upserts ClubFacility rows from the client's facility-level snapshot.
     *
     * @param array<string, int> $facilityLevels keyed by facility slug
     */
    private function processFacilityLevels(Club $club, array $facilityLevels): void
    {
        foreach ($facilityLevels as $slug => $level) {
            $facility = $this->clubFacilityRepository->findOrCreate($club, (string) $slug);
            $facility->setLevel((int) $level);
        }
    }

    /**
     * Upserts PlayerCareerStat rows from the client's season-to-date player stats.
     * playerStats is a cumulative snapshot (not a per-tick delta), so this overwrites
     * rather than accumulates — replaying a sync can't double-count.
     *
     * @param array<array{playerId: string, playerName?: string, appearances: int, goals: int, assists: int, averageRating: float}> $playerStats
     */
    private function processPlayerCareerStats(Club $club, array $playerStats): void
    {
        foreach ($playerStats as $data) {
            if (empty($data['playerId'])) {
                continue;
            }

            $playerName = $data['playerName'] ?? $data['playerId'];

            $stat = $this->playerCareerStatRepository->findOrCreate($club, (string) $data['playerId'], (string) $playerName);
            $stat->applySnapshot(
                (int) ($data['appearances'] ?? 0),
                (int) ($data['goals'] ?? 0),
                (int) ($data['assists'] ?? 0),
                (string) $playerName,
            );
        }
    }

    /**
     * Persists MatchResult entities from sync payload.
     * Uses fixtureId as idempotency key — re-sending the same fixture is a safe no-op.
     *
     * @param  MatchResultDto[] $results
     * @return string[]         IDs of fixtures successfully recorded
     */
    private function processMatchResults(Club $club, array $results): array
    {
        $syncedIds = [];

        foreach ($results as $dto) {
            // Idempotency: skip if this fixtureId has already been stored.
            if ($dto->fixtureId !== '') {
                $existing = $this->em->getRepository(MatchResult::class)
                    ->findOneBy(['fixtureId' => $dto->fixtureId]);
                if ($existing !== null) {
                    $syncedIds[] = $dto->fixtureId;
                    continue;
                }
            }

            // Derive goalsFor/Against from v2 home/away fields if provided,
            // falling back to legacy v1 fields.
            $goalsFor     = $dto->homeGoals !== 0 || $dto->awayGoals !== 0
                ? ($dto->isHome ? $dto->homeGoals : $dto->awayGoals)
                : $dto->goalsFor;
            $goalsAgainst = $dto->homeGoals !== 0 || $dto->awayGoals !== 0
                ? ($dto->isHome ? $dto->awayGoals : $dto->homeGoals)
                : $dto->goalsAgainst;

            $season = $dto->season > 0 ? $dto->season : $club->getCurrentSeason();
            $week   = $dto->round > 0  ? $dto->round  : $dto->week;

            $matchResult = new MatchResult(
                club:         $club,
                goalsFor:     $goalsFor,
                goalsAgainst: $goalsAgainst,
                week:         $week,
                season:       $season,
            );

            if ($dto->fixtureId !== '')        { $matchResult->setFixtureId($dto->fixtureId); }
            if ($dto->opponentClubName !== '')  { $matchResult->setOpponentClubName($dto->opponentClubName); }
            $matchResult->setIsHome($dto->isHome);
            if ($dto->homeGoals > 0 || $dto->awayGoals > 0) {
                $matchResult->setHomeGoals($dto->homeGoals);
                $matchResult->setAwayGoals($dto->awayGoals);
            }
            if ($dto->round > 0)               { $matchResult->setRound($dto->round); }
            if ($dto->playedAt !== '') {
                try {
                    $matchResult->setPlayedAt(new \DateTimeImmutable($dto->playedAt));
                } catch (\Exception) { /* invalid date — skip */ }
            }

            $this->em->persist($matchResult);

            if ($dto->fixtureId !== '') {
                $syncedIds[] = $dto->fixtureId;
            }
        }

        return $syncedIds;
    }

    /**
     * Server-detected milestones derived from the form array sent by the client.
     *
     * @return array<array{type: string, description: string, weekNumber: int}>
     */
    private function detectAchievements(SyncRequest $request): array
    {
        $form         = $request->form;
        $weekNumber   = $request->weekNumber;
        $achievements = [];

        if (empty($form)) {
            return [];
        }

        $last5 = array_slice($form, 0, 5);
        $last3 = array_slice($form, 0, 3);

        if (count($last5) === 5 && !in_array('L', $last5, true)) {
            $achievements[] = [
                'type'        => 'unbeaten_run_5',
                'description' => 'Unbeaten in the last 5 league games — keep the momentum going!',
                'weekNumber'  => $weekNumber,
            ];
        }

        if (count($last3) === 3 && $last3 === ['W', 'W', 'W']) {
            $achievements[] = [
                'type'        => 'winning_streak_3',
                'description' => 'Three wins on the bounce — the squad is flying!',
                'weekNumber'  => $weekNumber,
            ];
        }

        // Clean-sheet run: last 3 results and goals against all 0.
        if (count($request->matchResults) >= 3) {
            $last3Results = array_slice(array_reverse($request->matchResults), 0, 3);
            $cleanSheets  = array_filter($last3Results, function (MatchResultDto $r): bool {
                $ga = $r->homeGoals !== 0 || $r->awayGoals !== 0
                    ? ($r->isHome ? $r->awayGoals : $r->homeGoals)
                    : $r->goalsAgainst;
                return $ga === 0;
            });
            if (count($cleanSheets) === 3) {
                $achievements[] = [
                    'type'        => 'clean_sheet_run_3',
                    'description' => '3 consecutive clean sheets — the defence is impenetrable!',
                    'weekNumber'  => $weekNumber,
                ];
            }
        }

        return $achievements;
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

    /**
     * Persists Transfer records for players leaving the AMP club.
     * Also mirrors a SIGNING record on the receiving NPC club if the destination name matches.
     *
     * @param \App\Dto\TransferSyncDto[] $transfers
     */
    private function processOutgoingTransfers(Club $club, array $transfers, \DateTimeImmutable $syncedAt): void
    {
        foreach ($transfers as $dto) {
            $player = null;
            if (!empty($dto->playerId)) {
                $player = $this->findPlayerByClientId($dto->playerId);
            }

            $type = TransferType::tryFrom($dto->type) ?? TransferType::SALE;

            // AMP club record (player leaving)
            $transfer = new Transfer(
                player:              $player,
                club:                $club,
                destinationClubName: $dto->destinationClub,
                type:                $type,
                occurredAt:          $syncedAt,
            );
            $transfer->setPlayerName($dto->playerName ?: ($player?->getName()));
            $transfer->setPlayerPosition($dto->playerPosition ?: null);
            $transfer->setClubLeaving(
                $dto->startingClub
                ?? ($type === TransferType::SIGNING ? 'Free Agent' : $club->getName())
            );
            $transfer->setFee($dto->grossFee);
            $transfer->setAgentCommission($dto->agentCommission);
            $transfer->setNetProceeds($dto->netProceeds);
            $transfer->setSyncedAt($syncedAt);
            $this->em->persist($transfer);

            // Update player status
            if ($player !== null) {
                $player->setStatus(
                    $type === TransferType::AGENT_ASSISTED
                        ? PlayerStatus::TRANSFERRED_VIA_AGENT
                        : PlayerStatus::TRANSFERRED
                );
            }
        }
    }

    /**
     * Persists Transfer records for players signing into the AMP club.
     * Also mirrors a departure record on the source NPC club if the fromClub name matches.
     *
     * @param array<array{playerId: string, playerName: string, position: string, age: int, overallRating: int, fee: int, fromClub: string|null}> $signings
     */
    private function processSignings(Club $club, array $signings, \DateTimeImmutable $syncedAt): void
    {
        foreach ($signings as $signing) {
            $player = null;
            if (!empty($signing['playerId'])) {
                $player = $this->findPlayerByClientId($signing['playerId']);
            }

            $fromClub = $signing['fromClub'] ?? null;
            $fee      = (int) ($signing['fee'] ?? 0);
            $name     = $signing['playerName'] ?? ($player?->getName() ?? '');
            $position = $signing['position'] ?? null;

            // AMP club record (player arriving)
            $transfer = new Transfer(
                player:              $player,
                club:                $club,
                destinationClubName: $club->getName(),
                type:                TransferType::SIGNING,
                occurredAt:          $syncedAt,
            );
            $transfer->setPlayerName($name);
            $transfer->setPlayerPosition($position);
            $transfer->setClubLeaving($fromClub ?? 'Free Agent');
            $transfer->setFee($fee);
            $transfer->setSyncedAt($syncedAt);
            $this->em->persist($transfer);
        }
    }

    /**
     * Emits warning logs when debug diagnostic values exceed alert thresholds.
     * Value -1 on any storage field means the read failed — treated as unknown, not zero.
     *
     * @param array<string, mixed> $log
     */
    private function checkDebugLogThresholds(array $log): void
    {
        $context = [
            'platform'      => $log['platform']      ?? 'unknown',
            'capturedAt'    => $log['capturedAt']     ?? null,
            'tickDurationMs'=> $log['tickDurationMs'] ?? null,
        ];

        if (isset($log['tickDurationMs']) && $log['tickDurationMs'] > 3000) {
            $this->logger->warning('sync.debug: tick duration regression', array_merge($context, [
                'tickDurationMs' => $log['tickDurationMs'],
                'threshold'      => 3000,
            ]));
        }

        $storage = $log['storage'] ?? [];
        $sqlite  = $storage['sqlite'] ?? [];

        $totalKb = $sqlite['totalKb'] ?? -1;
        if ($totalKb !== -1 && $totalKb > 4500) {
            $this->logger->warning('sync.debug: storage approaching SQLite ceiling', array_merge($context, [
                'totalKb'   => $totalKb,
                'threshold' => 4500,
            ]));
        }

        $playerAppKeyCount = $sqlite['playerAppKeyCount'] ?? -1;
        if ($playerAppKeyCount !== -1 && $playerAppKeyCount > 500) {
            $this->logger->warning('sync.debug: playerAppKeyCount indicates pruning failure', array_merge($context, [
                'playerAppKeyCount' => $playerAppKeyCount,
                'threshold'         => 500,
            ]));
        }
    }

    /**
     * Resolves a pool Player from a client-supplied identifier, or null.
     *
     * Player::$id is a uuid column, but signings/transfers/updates carry ids the client
     * generated for players it already owns — pool rows are deleted the moment they're signed
     * (see MarketPoolService::assignToClub), so those ids are arbitrary strings, exactly as
     * PlayerCareerStat::$playerId documents. Passing one straight to find() made Doctrine's
     * UuidType throw a ConversionException, which SyncController does not catch: the whole
     * sync 500'd and every transfer, match result and career stat in the payload was lost.
     *
     * Every caller already treats a null player as "no pool row" and falls back to the
     * denormalised name/position on the Transfer, so degrading to null is safe.
     */
    private function findPlayerByClientId(mixed $id): ?Player
    {
        if (!is_string($id) || $id === '' || !Uuid::isValid($id)) {
            return null;
        }

        return $this->em->getRepository(Player::class)->find($id);
    }
}
