<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\NpcClub;
use App\Entity\Player;
use App\Entity\PoolConfig;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;

/**
 * Generates every entity one NPC club needs for a world-pack tier build — fresh,
 * in-memory, never persisted — directly from tier config, with no pool-table
 * dependency. The single per-club entry point shared by
 * WorldInitializationService::buildTierPack()'s synchronous loop (used both for
 * admin-triggered warming and InitializeController's on-demand lazy-fill path)
 * and the async per-club Messenger handler.
 */
class WorldPackClubGenerationService
{
    public function __construct(
        private readonly PlayerGenerationService $playerGen,
        private readonly StaffGenerationService $staffGen,
        private readonly ScoutGenerationService $scoutGen,
        private readonly WorldPackSnapshotBuilder $snapshotBuilder,
        private readonly NameGeneratorService $nameGenerator,
        private readonly \App\Service\Economy\WageModelResolver $wageModel,
    ) {}

    /**
     * @param array<string, mixed> $tierConf StarterConfig::getNpcSquadConfig()[tierKey] shape
     * @param array{min: int, max: int} $abilityRange
     * @param Agent[] $agents bounded agent pool for this tier (same subset for every
     *                        club in the tier, so an agent can represent multiple clubs)
     * @return Player[]
     */
    public function generatePlayers(
        array $tierConf,
        array $abilityRange,
        string $nationality,
        PoolConfig $poolConfig,
        array $agents,
        string $country = '',
        int $tier = 0,
        int $clubReputation = 50,
    ): array {
        $totalPlayers = random_int((int) $tierConf['playerMin'], (int) $tierConf['playerMax']);
        $foreignPct   = (int) $tierConf['foreignPercent'];
        $posCounts    = $this->snapshotBuilder->distributeByPosition($totalPlayers, $poolConfig);

        // An NPC side is a FIRST TEAM, so it generates inside the first-team age band
        // rather than PoolConfig's pool-wide range. That range has to span youth ages for
        // the pool to supply GET /api/players/youth, and without this every NPC club in
        // the pyramid was generated as children — the same bug as the starter pack, just
        // on the generation path rather than the draw.
        $ageRange = $poolConfig->firstTeamAgeRange();

        $players = [];
        foreach ($posCounts as $posValue => $posTotal) {
            $position      = PlayerPosition::from($posValue);
            $foreignCount  = (int) round($posTotal * $foreignPct / 100);
            $domesticCount = $posTotal - $foreignCount;

            for ($i = 0; $i < $domesticCount; $i++) {
                $players[] = $this->buildPlayer($position, $nationality, $abilityRange, $ageRange, $country, $tier, $clubReputation);
            }
            for ($i = 0; $i < $foreignCount; $i++) {
                $players[] = $this->buildPlayer($position, $this->foreignNationality($nationality), $abilityRange, $ageRange, $country, $tier, $clubReputation);
            }
        }

        $this->snapshotBuilder->assignAgents($players, $agents);

        return $players;
    }

    /**
     * Manager/Coach/Chairman are unfiltered by nationality (long-standing NPC-club
     * precedent); DOF/Facility Manager use the club's own nationality, matching
     * StarterPackService's pattern for the player's own club.
     *
     * @param array<string, mixed> $tierConf
     * @return Staff[]
     */
    public function generateStaff(array $tierConf, string $nationality, string $country = '', int $tier = 0): array
    {
        $staff = [];

        for ($i = 0; $i < (int) $tierConf['managerCount']; $i++) {
            $staff[] = $this->staffGen->build(StaffRole::MANAGER, null, $country, $tier);
        }
        for ($i = 0; $i < (int) $tierConf['coachCount']; $i++) {
            $staff[] = $this->staffGen->build(StaffRole::COACH, null, $country, $tier);
        }
        for ($i = 0; $i < (int) $tierConf['chairmanCount']; $i++) {
            $staff[] = $this->staffGen->build(StaffRole::CHAIRMAN, null, $country, $tier);
        }
        for ($i = 0; $i < (int) ($tierConf['directorOfFootballCount'] ?? 0); $i++) {
            $staff[] = $this->staffGen->build(StaffRole::DIRECTOR_OF_FOOTBALL, $nationality, $country, $tier);
        }
        for ($i = 0; $i < (int) ($tierConf['facilityManagerCount'] ?? 0); $i++) {
            $staff[] = $this->staffGen->build(StaffRole::FACILITY_MANAGER, $nationality, $country, $tier);
        }

        return $staff;
    }

    /**
     * @param array<string, mixed> $tierConf
     * @return Scout[]
     */
    public function generateScouts(array $tierConf, string $nationality): array
    {
        $scouts = [];
        for ($i = 0; $i < (int) ($tierConf['scoutCount'] ?? 0); $i++) {
            $scouts[] = $this->scoutGen->build($nationality);
        }
        return $scouts;
    }

    /**
     * @param Player[] $players
     * @param Staff[]  $staff
     * @param Scout[]  $scouts
     */
    public function buildSnapshot(NpcClub $club, array $players, array $staff, array $scouts): array
    {
        return $this->snapshotBuilder->buildClubSnapshot($club, $players, $staff, $scouts);
    }

    /**
     * Sets a contract value the same way MarketPoolService::generatePlayers() does for
     * pool players — PlayerGenerationService::generate() itself leaves this at its 0
     * default, so world-pack NPC players need it set explicitly to match the shape
     * pool-drawn NPC players always had (contractValue is part of buildPlayerSnapshot()).
     */
    /** @param array{min: int, max: int} $abilityRange */
    /**
     * @param array{min: int, max: int}      $abilityRange
     * @param array{min: int, max: int}|null $ageRange First-team band; null keeps PoolConfig's
     *                                       pool-wide range (callers that are not squads).
     */
    private function buildPlayer(
        PlayerPosition $position,
        string $nationality,
        array $abilityRange,
        ?array $ageRange = null,
        string $country = '',
        int $tier = 0,
        int $clubReputation = 50,
    ): Player {
        $player = $this->playerGen->generate($position, RecruitmentSource::YOUTH_INTAKE, $nationality, $abilityRange, $ageRange);

        // THROUGH THE RESOLVER. The expression that was here — `(int) ($ability * roll *
        // multiplier)`, with its own copy of the tier-walk — now lives in one place shared with
        // MarketPoolService, StaffGenerationService and GenerateMarketDataCommand. In `legacy`
        // mode it reproduces this byte for byte, truncating cast included.
        //
        // THE COUNTRY AND TIER ARE WHAT MAKE THIS THE PRICED POPULATION. A world-pack club is
        // generated for a known country and tier, so the turnover model can price it at
        // generation and the figure is stored in the pack. That is exactly why flipping the
        // model needs a WORLD_PACK_VERSION bump: packs hold wages.
        $player->setContractValue(
            $this->wageModel->playerWeeklyPence(
                $player->getCurrentAbility(),
                $country,
                $tier,
                $clubReputation,
            ),
        );

        return $player;
    }

    /** Picks a random nationality different from $exclude where possible (bounded retries). */
    private function foreignNationality(string $exclude): string
    {
        for ($i = 0; $i < 5; $i++) {
            $candidate = $this->nameGenerator->getRandomNationality();
            if ($candidate !== $exclude) {
                return $candidate;
            }
        }
        return $this->nameGenerator->getRandomNationality();
    }
}
