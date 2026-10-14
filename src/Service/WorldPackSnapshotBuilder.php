<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\NpcClub;
use App\Entity\Player;
use App\Entity\PoolConfig;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Enum\Country;
use App\Enum\PlayerPosition;
use App\Enum\Tier;

/**
 * Pure entity→array snapshot mappers plus the position/agent-distribution
 * helpers they depend on. Extracted from WorldInitializationService so a
 * per-club generation service can build snapshots without creating a
 * circular dependency (WorldInitializationService → per-club generator →
 * WorldInitializationService). Stateless — no pool-table or DB dependency;
 * every method here works identically on a freshly-built, never-persisted
 * entity as it does on one pulled from a pool table.
 */
class WorldPackSnapshotBuilder
{
    public function __construct(
        private readonly PlayerSnapshotSerializer $playerSnapshots = new PlayerSnapshotSerializer(),
    ) {}

    /**
     * Distributes $total players across GK/DEF/MID/ATT using PoolConfig position weights.
     * Uses the largest-remainder method so the counts always sum exactly to $total.
     *
     * @return array<string, int>  Keys are PlayerPosition backed-enum values ('GK','DEF','MID','ATT')
     */
    public function distributeByPosition(int $total, PoolConfig $config): array
    {
        $weights = [
            PlayerPosition::GOALKEEPER->value => $config->getPositionWeightGk(),
            PlayerPosition::DEFENDER->value   => $config->getPositionWeightDef(),
            PlayerPosition::MIDFIELDER->value => $config->getPositionWeightMid(),
            PlayerPosition::ATTACKER->value   => $config->getPositionWeightAtt(),
        ];

        $totalWeight = array_sum($weights);
        $counts      = [];
        $fractions   = [];
        $assigned    = 0;

        foreach ($weights as $pos => $weight) {
            $exact        = $total * $weight / $totalWeight;
            $counts[$pos] = (int) floor($exact);
            $fractions[$pos] = $exact - $counts[$pos];
            $assigned    += $counts[$pos];
        }

        // Distribute remainder to positions with the largest fractional parts
        $remainder = $total - $assigned;
        arsort($fractions);
        foreach (array_keys($fractions) as $pos) {
            if ($remainder <= 0) break;
            $counts[$pos]++;
            $remainder--;
        }

        return $counts;
    }

    /**
     * Reassigns each player a random agent from the available agent pool. Multiple
     * players may reference the same agent — a many-to-one relationship is the
     * intended shape (one agent represents several players). No-op when the pool is
     * empty. Called at world-pack generation time, before players are snapshotted
     * and deleted, so the in-memory FK is read straight into the snapshot with no flush.
     *
     * @param Player[] $players
     * @param Agent[]  $agents
     */
    public function assignAgents(array $players, array $agents): void
    {
        if ($agents === []) {
            return;
        }
        foreach ($players as $player) {
            $player->setAgent($agents[array_rand($agents)]);
        }
    }

    public function buildClubSnapshot(NpcClub $club, array $players, array $staff, array $scouts = []): array
    {
        return [
            'id'              => (string) $club->getId(),
            'name'            => $club->getName(),
            'abbreviation'    => $club->getAbbreviation() ?? ClubInitializationService::generateAbbreviation($club->getName()),
            'tier'            => $club->getTier(),
            'reputation'      => $club->getReputation(),
            'startingBalance' => $club->getBalance(),
            'primaryColor'   => $club->getPrimaryColor(),
            'secondaryColor' => $club->getSecondaryColor(),
            'identity'       => $club->getIdentity(),
            'stadiumName'    => $club->getStadiumName(),
            'facilities'     => $club->getFacilities(),
            'region'         => $club->getRegion(),
            'citySize'       => $club->getCitySize()->value,
            'populationSize' => $club->getPopulationSize(),
            'isCapital'      => $club->isCapital(),
            'personality'    => [
                'playingStyle'       => $club->getPlayingStyle(),
                'financialApproach'  => $club->getFinancialApproach(),
                'managerTemperament' => $club->getManagerTemperament(),
            ],
            'players' => array_map(fn(Player $p) => $this->buildPlayerSnapshot($p), $players),
            'staff'   => array_map(fn(Staff $s) => $this->buildStaffSnapshot($s), $staff),
            'scouts'  => array_map(fn(Scout $s) => $this->buildScoutSnapshot($s), $scouts),
        ];
    }

    /**
     * Delegates to PlayerSnapshotSerializer, which owns the single canonical player shape
     * this method's own output became the model for — see that class. Guardians are
     * deliberately left out here: Player::$guardians is lazy, so including them would fire
     * a query per player across a whole country's pack.
     *
     * Emits three keys this method did not before — `tier`, `overall` and `potentialTier`;
     * nothing it used to emit was removed or changed.
     */
    public function buildPlayerSnapshot(Player $player): array
    {
        return $this->playerSnapshots->serialize($player);
    }

    public function buildStaffSnapshot(Staff $staff): array
    {
        // getRole() returns StaffRole backed enum — use ->value
        return [
            'id'              => (string) $staff->getId(),
            'firstName'       => $staff->getFirstName(),
            'lastName'        => $staff->getLastName(),
            'dateOfBirth'     => $staff->getDob()?->format('Y-m-d'),
            'nationality'     => $staff->getNationality() ?? '',
            'countryCode'     => $staff->getNationality() !== null ? Country::fromNationality($staff->getNationality())?->value : null,
            'role'            => $staff->getRole()->value,
            'tier'            => Tier::fromScore($staff->getCoachingAbility())->value,
            'coachingAbility' => $staff->getCoachingAbility(),
            'scoutingRange'   => $staff->getScoutingRange(),
            'weeklySalary'    => $staff->getWeeklySalary(),
            'morale'          => $staff->getMorale(),
            'specialisms'     => $staff->getSpecialisms() ?? [],
            'appearance'      => $staff->getAppearance(),
            'personality'     => $staff->getPersonality()->toArray(),
        ];
    }

    public function buildScoutSnapshot(Scout $scout): array
    {
        return [
            'id'          => (string) $scout->getId(),
            'name'        => $scout->getName(),
            'dateOfBirth' => $scout->getDob()?->format('Y-m-d'),
            'nationality' => $scout->getNationality() ?? '',
            'countryCode' => $scout->getNationality() !== null ? Country::fromNationality($scout->getNationality())?->value : null,
            'experience'  => $scout->getExperience(),
            'tier'        => Tier::fromScore($scout->getExperience())->value,
            'judgements'  => $scout->getJudgements(),
            'appearance'  => $scout->getAppearance(),
            'personality' => $scout->getPersonality()->toArray(),
        ];
    }
}
