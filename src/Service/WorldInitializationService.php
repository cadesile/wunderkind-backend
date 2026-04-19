<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\NpcClub;
use App\Entity\League;
use App\Entity\Player;
use App\Entity\Staff;
use App\Enum\StaffRole;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;
use App\Repository\PlayerRepository;
use App\Repository\StaffRepository;
use App\Repository\StarterConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

class WorldInitializationService
{
    /** Ability range by tier — indexes 1-8 */
    private const ABILITY_RANGES = [
        1 => ['min' => 75, 'max' => 95],
        2 => ['min' => 65, 'max' => 85],
        3 => ['min' => 55, 'max' => 75],
        4 => ['min' => 45, 'max' => 65],
        5 => ['min' => 35, 'max' => 55],
        6 => ['min' => 25, 'max' => 45],
        7 => ['min' => 15,  'max' => 35],
        8 => ['min' => 10,  'max' => 25],
    ];

    private const STARTER_ABILITY_RANGES = [
        'local'    => ['min' => 5,  'max' => 30],
        'regional' => ['min' => 20, 'max' => 45],
        'national' => ['min' => 40, 'max' => 65],
        'elite'    => ['min' => 60, 'max' => 85],
    ];

    public function __construct(
        private readonly LeagueRepository        $leagueRepository,
        private readonly NpcClubRepository       $npcClubRepository,
        private readonly PlayerRepository        $playerRepository,
        private readonly StaffRepository         $staffRepository,
        private readonly StarterConfigRepository $starterConfigRepository,
        private readonly FixtureGenerationService $fixtureGenerationService,
        private readonly EntityManagerInterface  $em,
    ) {}

    public function initialize(Club $club): array
    {
        $country       = $club->getCountry();
        $starterConfig = $this->starterConfigRepository->getConfig();
        $npcConfig     = $starterConfig->getNpcSquadConfig();
        $leagueRanges  = $starterConfig->getLeagueAbilityRanges();

        $leagues      = $this->leagueRepository->findByCountry($country);
        $leaguesData  = [];
        $npcPlayerIds = [];
        $npcStaffIds  = [];

        foreach ($leagues as $league) {
            $tier         = $league->getTier();
            $tierKey      = (string) $tier;
            $tierConf     = $npcConfig[$tierKey] ?? $this->defaultTierConfig($tier);
            
            // Use configured ranges if available, otherwise fallback to hardcoded defaults
            $abilityRange = $leagueRanges[$country][$tierKey] ?? self::ABILITY_RANGES[$tier] ?? ['min' => 5, 'max' => 35];
            
            $npcClubs     = $this->npcClubRepository->findByLeague($league);
            $clubsData    = [];
            $allClubIds   = [];

            // Add the player's club to the fixture list if it belongs to this league
            if ($club->getCurrentLeague()?->getId()->toBinary() === $league->getId()->toBinary()) {
                $allClubIds[] = (string) $club->getId();
            }

            foreach ($npcClubs as $npcClub) {
                $allClubIds[] = (string) $npcClub->getId();
                $totalPlayers  = random_int((int) $tierConf['playerMin'], (int) $tierConf['playerMax']);
                $foreignCount  = (int) round($totalPlayers * (int) $tierConf['foreignPercent'] / 100);
                $domesticCount = $totalPlayers - $foreignCount;

                $domestic = $this->playerRepository->findForWorldInit(
                    $abilityRange['min'], $abilityRange['max'], $country, $domesticCount
                );
                if (count($domestic) < $domesticCount) {
                    $deficit = $domesticCount - count($domestic);
                    $extra   = $this->playerRepository->findForeignForWorldInit(
                        $abilityRange['min'], $abilityRange['max'], '__none__', $deficit // '__none__' is an impossible nationality value used to draw from any nationality (no exclusion)
                    );
                    $domestic = array_merge($domestic, $extra);
                }

                $foreign = $this->playerRepository->findForeignForWorldInit(
                    $abilityRange['min'], $abilityRange['max'], $country, $foreignCount
                );
                if (count($foreign) < $foreignCount) {
                    $deficit = $foreignCount - count($foreign);
                    $extra   = $this->playerRepository->findForWorldInit(
                        $abilityRange['min'], $abilityRange['max'], $country, $deficit
                    );
                    $foreign = array_merge($foreign, $extra);
                }

                $players  = array_unique(array_merge($domestic, $foreign), SORT_REGULAR);
                $managers = $this->staffRepository->findInPoolByRoleRandom(StaffRole::MANAGER,    (int) $tierConf['managerCount']);
                $coaches  = $this->staffRepository->findInPoolByRoleRandom(StaffRole::HEAD_COACH,  (int) $tierConf['coachCount']);
                $chairmen = $this->staffRepository->findInPoolByRoleRandom(StaffRole::CHAIRMAN,    (int) $tierConf['chairmanCount']);
                $staff    = array_merge($managers, $coaches, $chairmen);

                foreach ($players as $p) { $npcPlayerIds[] = (string) $p->getId(); }
                foreach ($staff   as $s) { $npcStaffIds[]  = (string) $s->getId(); }

                $clubsData[] = $this->buildClubSnapshot($npcClub, $players, $staff);
            }

            $fixtures = $this->fixtureGenerationService->generate($allClubIds);
            $leaguesData[] = $this->buildLeagueSnapshot($league, $clubsData, $fixtures);
        }

        // AMP starter pack
        $tierStr  = $starterConfig->getStarterClubTier();
        $ampRange = self::STARTER_ABILITY_RANGES[$tierStr] ?? ['min' => 5, 'max' => 30];

        $ampPlayers = $this->playerRepository->findForWorldInit(
            $ampRange['min'], $ampRange['max'], $country, $starterConfig->getStarterPlayerCount()
        );
        if (count($ampPlayers) < $starterConfig->getStarterPlayerCount()) {
            $deficit = $starterConfig->getStarterPlayerCount() - count($ampPlayers);
            $extra   = $this->playerRepository->findForeignForWorldInit(
                $ampRange['min'], $ampRange['max'], '__none__', $deficit // '__none__' is an impossible nationality value used to draw from any nationality (no exclusion)
            );
            $ampPlayers = array_merge($ampPlayers, $extra);
        }

        $ampCoaches = $this->staffRepository->findInPoolByRoleRandom(StaffRole::HEAD_COACH, $starterConfig->getStarterCoachCount());
        $ampScouts  = $this->staffRepository->findInPoolByRoleRandom(StaffRole::SCOUT,      $starterConfig->getStarterScoutCount());
        $ampStaff   = array_merge($ampCoaches, $ampScouts);

        foreach ($ampPlayers as $p) { $p->setClub($club); }
        foreach ($ampStaff   as $s) { $s->setClub($club); }

        $this->playerRepository->deleteByIds($npcPlayerIds);
        $this->staffRepository->deleteByIds($npcStaffIds);

        // Note: Doctrine wraps flush() in an implicit DB transaction on PostgreSQL.
        // All DML (AMP FK assignments + NPC deletes) is atomically committed here.
        $club->setWorldInitializedAt(new \DateTimeImmutable());
        $this->em->flush();

        return [
            'leagues'    => $leaguesData,
            'ampStarter' => [
                'players' => array_map(fn(Player $p) => $this->buildPlayerSnapshot($p), $ampPlayers),
                'staff'   => array_map(fn(Staff $s) => $this->buildStaffSnapshot($s), $ampStaff),
            ],
        ];
    }

    private function buildLeagueSnapshot(League $league, array $clubsData, array $fixtures): array
    {
        // getLeagueReputationTier() returns ?ReputationTier (backed enum: string)
        return [
            'id'             => (string) $league->getId(),
            'tier'           => $league->getTier(),
            'name'           => $league->getName(),
            'country'        => $league->getCountry(),
            'promotionSpots' => $league->getPromotionSpots(),
            'reputationTier' => $league->getLeagueReputationTier()?->value,
            'clubs'          => $clubsData,
            'fixtures'       => $fixtures,
        ];
    }

    private function buildClubSnapshot(NpcClub $club, array $players, array $staff): array
    {
        return [
            'id'             => (string) $club->getId(),
            'name'           => $club->getName(),
            'tier'           => $club->getTier(),
            'reputation'     => $club->getReputation(),
            'primaryColor'   => $club->getPrimaryColor(),
            'secondaryColor' => $club->getSecondaryColor(),
            'stadiumName'    => $club->getStadiumName(),
            'facilities'     => $club->getFacilities(),
            'personality'    => [
                'playingStyle'       => $club->getPlayingStyle(),
                'financialApproach'  => $club->getFinancialApproach(),
                'managerTemperament' => $club->getManagerTemperament(),
            ],
            'players' => array_map(fn(Player $p) => $this->buildPlayerSnapshot($p), $players),
            'staff'   => array_map(fn(Staff $s) => $this->buildStaffSnapshot($s), $staff),
        ];
    }

    private function buildPlayerSnapshot(Player $player): array
    {
        // getPersonality() returns PersonalityProfile (embedded object)
        // getPosition() returns PlayerPosition backed enum — use ->value
        $p = $player->getPersonality();
        return [
            'id'          => (string) $player->getId(),
            'firstName'   => $player->getFirstName(),
            'lastName'    => $player->getLastName(),
            'position'    => $player->getPosition()->value,
            'nationality' => $player->getNationality(),
            'dateOfBirth' => $player->getDateOfBirth()->format('Y-m-d'),
            'pace'        => $player->getPace(),
            'technical'   => $player->getTechnical(),
            'vision'      => $player->getVision(),
            'power'       => $player->getPower(),
            'stamina'     => $player->getStamina(),
            'heart'       => $player->getHeart(),
            'confidence'  => $p->getConfidence(),
            'maturity'    => $p->getMaturity(),
            'teamwork'    => $p->getTeamwork(),
            'leadership'  => $p->getLeadership(),
            'ego'         => $p->getEgo(),
            'bravery'     => $p->getBravery(),
            'greed'       => $p->getGreed(),
            'loyalty'     => $p->getLoyalty(),
        ];
    }

    private function buildStaffSnapshot(Staff $staff): array
    {
        // getRole() returns StaffRole backed enum — use ->value
        return [
            'id'              => (string) $staff->getId(),
            'firstName'       => $staff->getFirstName(),
            'lastName'        => $staff->getLastName(),
            'role'            => $staff->getRole()->value,
            'coachingAbility' => $staff->getCoachingAbility(),
            'nationality'     => $staff->getNationality() ?? '',
        ];
    }

    private function defaultTierConfig(int $tier): array
    {
        return match (true) {
            $tier <= 2 => ['playerMin' => 20, 'playerMax' => 24, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'foreignPercent' => 60],
            $tier <= 4 => ['playerMin' => 16, 'playerMax' => 20, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'foreignPercent' => 25],
            $tier <= 6 => ['playerMin' => 13, 'playerMax' => 17, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'foreignPercent' => 12],
            default    => ['playerMin' => 11, 'playerMax' => 14, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'foreignPercent' => 4],
        };
    }
}
