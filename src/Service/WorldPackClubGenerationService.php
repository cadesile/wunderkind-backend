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
use App\Repository\GameConfigRepository;

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
        private readonly GameConfigRepository $gameConfigRepo,
    ) {}

    /**
     * @param array<string, mixed> $tierConf StarterConfig::getNpcSquadConfig()[tierKey] shape
     * @param array{min: int, max: int} $abilityRange
     * @param Agent[] $agents bounded agent pool for this tier (same subset for every
     *                        club in the tier, so an agent can represent multiple clubs)
     * @return Player[]
     */
    public function generatePlayers(array $tierConf, array $abilityRange, string $nationality, PoolConfig $poolConfig, array $agents): array
    {
        $totalPlayers = random_int((int) $tierConf['playerMin'], (int) $tierConf['playerMax']);
        $foreignPct   = (int) $tierConf['foreignPercent'];
        $posCounts    = $this->snapshotBuilder->distributeByPosition($totalPlayers, $poolConfig);

        $players = [];
        foreach ($posCounts as $posValue => $posTotal) {
            $position      = PlayerPosition::from($posValue);
            $foreignCount  = (int) round($posTotal * $foreignPct / 100);
            $domesticCount = $posTotal - $foreignCount;

            for ($i = 0; $i < $domesticCount; $i++) {
                $players[] = $this->buildPlayer($position, $nationality, $abilityRange);
            }
            for ($i = 0; $i < $foreignCount; $i++) {
                $players[] = $this->buildPlayer($position, $this->foreignNationality($nationality), $abilityRange);
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
    public function generateStaff(array $tierConf, string $nationality): array
    {
        $staff = [];

        for ($i = 0; $i < (int) $tierConf['managerCount']; $i++) {
            $staff[] = $this->staffGen->build(StaffRole::MANAGER);
        }
        for ($i = 0; $i < (int) $tierConf['coachCount']; $i++) {
            $staff[] = $this->staffGen->build(StaffRole::COACH);
        }
        for ($i = 0; $i < (int) $tierConf['chairmanCount']; $i++) {
            $staff[] = $this->staffGen->build(StaffRole::CHAIRMAN);
        }
        for ($i = 0; $i < (int) ($tierConf['directorOfFootballCount'] ?? 0); $i++) {
            $staff[] = $this->staffGen->build(StaffRole::DIRECTOR_OF_FOOTBALL, $nationality);
        }
        for ($i = 0; $i < (int) ($tierConf['facilityManagerCount'] ?? 0); $i++) {
            $staff[] = $this->staffGen->build(StaffRole::FACILITY_MANAGER, $nationality);
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
    private function buildPlayer(PlayerPosition $position, string $nationality, array $abilityRange): Player
    {
        $player = $this->playerGen->generate($position, RecruitmentSource::YOUTH_INTAKE, $nationality, $abilityRange);

        $gc          = $this->gameConfigRepo->getConfig();
        $multiplier  = $this->playerWageMultiplier($player->getCurrentAbility());
        $baseWage    = $player->getCurrentAbility() * random_int($gc->getContractValueRandMin(), $gc->getContractValueRandMax());
        $player->setContractValue((int) ($baseWage * $multiplier));

        return $player;
    }

    private function playerWageMultiplier(int $ability): float
    {
        $tiers = $this->gameConfigRepo->getConfig()->getWageMultiplierTiers();
        foreach ($tiers as $tier) {
            if ($tier['maxAbility'] === null || $ability < $tier['maxAbility']) {
                return (float) $tier['playerMultiplier'];
            }
        }

        $last = end($tiers);
        return (float) ($last['playerMultiplier'] ?? 1.0);
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
