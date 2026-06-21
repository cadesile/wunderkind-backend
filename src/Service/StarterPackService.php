<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Entity\StarterConfig;
use App\Enum\PlayerPosition;
use App\Enum\RecruitmentSource;
use App\Enum\StaffRole;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Repository\StarterConfigRepository;
use App\Service\ClubInitializationService;
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
        private readonly MarketPoolService          $marketPoolService,
    ) {}

    /**
     * Assembles the AMP starter squad from the pool, assigns players/staff to the club,
     * sets starterInitializedAt, flushes, and returns the starter payload array.
     */
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

        $this->prewarmPoolForClub($club, $starterConfig, $ampNationality);

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
        $ampPlayers = array_values(array_unique($ampPlayers, SORT_REGULAR));

        $ampStaff = array_merge(
            $this->fillStaffRole(StaffRole::MANAGER,              $starterConfig->getStarterManagerCount(),            $ampNationality),
            $this->fillStaffRole(StaffRole::COACH,                $starterConfig->getStarterCoachCount(),              $ampNationality),
            $this->fillStaffRole(StaffRole::DIRECTOR_OF_FOOTBALL, $starterConfig->getStarterDirectorOfFootballCount(), $ampNationality),
            $this->fillStaffRole(StaffRole::FACILITY_MANAGER,     $starterConfig->getStarterFacilityManagerCount(),    $ampNationality),
            $this->fillStaffRole(StaffRole::CHAIRMAN,             $starterConfig->getStarterChairmanCount(),           $ampNationality),
        );

        // Scouts are not consumed from the pool (no assignedAt guard) — this mirrors
        // the pre-existing behaviour in WorldInitializationService::initialize().
        $ampScouts = $this->scoutRepository->findInPool($starterConfig->getStarterScoutCount(), nationality: $ampNationality);
        if (count($ampScouts) < $starterConfig->getStarterScoutCount()) {
            $deficit   = $starterConfig->getStarterScoutCount() - count($ampScouts);
            $ampScouts = array_merge($ampScouts, $this->scoutRepository->findInPool($deficit));
        }
        $ampScouts = array_values(array_unique($ampScouts, SORT_REGULAR));

        foreach ($ampPlayers as $p) { $p->setClub($club); }
        foreach ($ampStaff   as $s) { $s->setClub($club); }

        $club->setStarterInitializedAt(new \DateTimeImmutable());
        $this->em->flush();

        return [
            'players' => array_map(
                fn(Player $p) => $this->worldInitializationService->buildPlayerSnapshot($p),
                $ampPlayers
            ),
            'staff'   => array_map(
                fn(Staff $s) => $this->worldInitializationService->buildStaffSnapshot($s),
                $ampStaff
            ),
            'scouts'  => array_map(
                fn(Scout $s) => $this->worldInitializationService->buildScoutSnapshot($s),
                $ampScouts
            ),
        ];
    }

    private function prewarmPoolForClub(Club $club, StarterConfig $config, string $nationality): void
    {
        $this->marketPoolService->generatePlayers(
            $config->getStarterPlayerCount() * 2,
            RecruitmentSource::YOUTH_INTAKE,
            $nationality,
        );

        $staffRoles = [
            [StaffRole::MANAGER,              $config->getStarterManagerCount()],
            [StaffRole::COACH,                $config->getStarterCoachCount()],
            [StaffRole::DIRECTOR_OF_FOOTBALL, $config->getStarterDirectorOfFootballCount()],
            [StaffRole::FACILITY_MANAGER,     $config->getStarterFacilityManagerCount()],
            [StaffRole::CHAIRMAN,             $config->getStarterChairmanCount()],
        ];

        foreach ($staffRoles as [$role, $count]) {
            if ($count > 0) {
                $this->marketPoolService->generateStaffForRole($role, $count, $nationality);
            }
        }

        $this->marketPoolService->generateScouts($config->getStarterScoutCount(), $nationality);
    }

    private function fillStaffRole(StaffRole $role, int $limit, string $nationality): array
    {
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
