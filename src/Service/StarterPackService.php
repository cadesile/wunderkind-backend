<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Entity\StarterConfig;
use App\Enum\PlayerPosition;
use App\Enum\StaffRole;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Repository\StarterConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

class StarterPackService
{
    public function __construct(
        private readonly PlayerRepository           $playerRepository,
        private readonly StaffRepository            $staffRepository,
        private readonly ScoutRepository            $scoutRepository,
        private readonly StarterConfigRepository    $starterConfigRepository,
        private readonly PoolConfigRepository       $poolConfigRepository,
        private readonly WorldInitializationService $worldInitializationService,
        private readonly EntityManagerInterface     $em,
    ) {}

    public function initialize(Club $club): array
    {
        $starterConfig  = $this->starterConfigRepository->getConfig();
        $leagueRanges   = $starterConfig->getLeagueAbilityRanges();
        $country        = $club->getCountry();
        $ampLeagueTier  = $club->getCurrentLeague()?->getTier() ?? 8;
        $ampRangeRaw    = $leagueRanges[$country][(string) $ampLeagueTier]
            ?? WorldInitializationService::ABILITY_RANGES[$ampLeagueTier]
            ?? ['min' => 5, 'max' => 35];
        $ampRange       = ['min' => (int) $ampRangeRaw['min'], 'max' => (int) $ampRangeRaw['max']];
        $ampNationality = ClubInitializationService::countryToNationality($country) ?? $country;

        $poolConfig = $this->poolConfigRepository->getConfig();
        $posCounts  = $this->worldInitializationService->distributeByPosition(
            $starterConfig->getStarterPlayerCount(),
            $poolConfig
        );
        $ampPlayers = [];

        foreach ($posCounts as $posValue => $count) {
            $position   = PlayerPosition::from($posValue);
            $posPlayers = $this->playerRepository->findForWorldInitByPositionAndNationality(
                $ampRange['min'], $ampRange['max'], $position, $ampNationality, $count
            );
            if (count($posPlayers) < $count) {
                $deficit    = $count - count($posPlayers);
                $extra      = $this->playerRepository->findForeignForWorldInitByPosition(
                    $ampRange['min'], $ampRange['max'], '__none__', $position, $deficit
                );
                $posPlayers = array_merge($posPlayers, $extra);
            }
            $ampPlayers = array_merge($ampPlayers, $posPlayers);
        }

        $ampStaff = array_merge(
            $this->fillStaffRole(StaffRole::MANAGER,              $starterConfig->getStarterManagerCount(),            $ampNationality),
            $this->fillStaffRole(StaffRole::COACH,                $starterConfig->getStarterCoachCount(),              $ampNationality),
            $this->fillStaffRole(StaffRole::DIRECTOR_OF_FOOTBALL, $starterConfig->getStarterDirectorOfFootballCount(), $ampNationality),
            $this->fillStaffRole(StaffRole::FACILITY_MANAGER,     $starterConfig->getStarterFacilityManagerCount(),    $ampNationality),
            $this->fillStaffRole(StaffRole::CHAIRMAN,             $starterConfig->getStarterChairmanCount(),           $ampNationality),
        );

        $ampScouts = $this->scoutRepository->findInPool($starterConfig->getStarterScoutCount(), nationality: $ampNationality);
        if (count($ampScouts) < $starterConfig->getStarterScoutCount()) {
            $deficit   = $starterConfig->getStarterScoutCount() - count($ampScouts);
            $ampScouts = array_merge($ampScouts, $this->scoutRepository->findInPool($deficit));
        }

        // Build snapshots before deletion (entities must exist to serialise)
        $playerSnapshots = array_map(
            fn(Player $p) => $this->worldInitializationService->buildPlayerSnapshot($p),
            $ampPlayers
        );
        $staffSnapshots = array_map(
            fn(Staff $s) => $this->worldInitializationService->buildStaffSnapshot($s),
            $ampStaff
        );
        $scoutSnapshots = array_map(
            fn(Scout $s) => $this->worldInitializationService->buildScoutSnapshot($s),
            $ampScouts
        );

        // Consume pool entities — delete from DB, frontend stores snapshots locally
        foreach ($ampPlayers as $p) { $this->em->remove($p); }
        foreach ($ampStaff   as $s) { $this->em->remove($s); }

        $club->setStarterInitializedAt(new \DateTimeImmutable());
        $this->em->flush();

        return [
            'players' => $playerSnapshots,
            'staff'   => $staffSnapshots,
            'scouts'  => $scoutSnapshots,
        ];
    }

    private function fillStaffRole(StaffRole $role, int $limit, string $nationality): array
    {
        if ($limit <= 0) return [];
        $results = $this->staffRepository->findInPoolByRoleRandom($role, $limit, $nationality);
        if (count($results) < $limit) {
            $deficit = $limit - count($results);
            $results = array_merge(
                $results,
                $this->staffRepository->findInPoolByRoleRandom($role, $deficit)
            );
        }
        return $results;
    }
}
