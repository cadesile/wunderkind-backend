<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\GameConfig;
use App\Entity\NpcClub;
use App\Entity\League;
use App\Entity\Player;
use App\Entity\PoolConfig;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Enum\CompanySize;
use App\Enum\PlayerPosition;
use App\Enum\StaffRole;
use App\Repository\GameConfigRepository;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Repository\StarterConfigRepository;
use App\Service\ClubInitializationService;
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

    public function __construct(
        private readonly LeagueRepository         $leagueRepository,
        private readonly NpcClubRepository        $npcClubRepository,
        private readonly PlayerRepository         $playerRepository,
        private readonly ScoutRepository          $scoutRepository,
        private readonly StaffRepository          $staffRepository,
        private readonly StarterConfigRepository  $starterConfigRepository,
        private readonly GameConfigRepository     $gameConfigRepository,
        private readonly PoolConfigRepository     $poolConfigRepository,
        private readonly FixtureGenerationService $fixtureGenerationService,
        private readonly EntityManagerInterface   $em,
    ) {}

    /**
     * Builds the full NPC league pack for a country: all tiers, with fresh squads drawn from
     * the player pool and fixtures generated. NPC players/staff used for snapshots are deleted
     * from the pool immediately (DQL, no flush required).
     *
     * Safe to call independently of initialize() — used by LeagueService on season conclusion.
     *
     * @return array{leagues: array}
     */
    public function buildLeaguesPack(Club $club, string $country): array
    {
        $starterConfig = $this->starterConfigRepository->getConfig();
        $npcConfig     = $starterConfig->getNpcSquadConfig();
        $leagueRanges  = $starterConfig->getLeagueAbilityRanges();

        $poolConfig   = $this->poolConfigRepository->getConfig();
        $gameConfig   = $this->gameConfigRepository->getConfig();
        $leagues      = $this->leagueRepository->findByCountry($country);
        $leaguesData  = [];
        $npcPlayerIds = [];
        $npcStaffIds  = [];
        $nationality  = ClubInitializationService::countryToNationality($country) ?? $country;

        foreach ($leagues as $league) {
            $tier         = $league->getTier();
            $tierKey      = (string) $tier;
            $tierConf     = $npcConfig[$tierKey] ?? $this->defaultTierConfig($tier);

            // Use configured ranges if available and non-zero, otherwise fallback to hardcoded defaults
            $configured   = $leagueRanges[$country][$tierKey] ?? null;
            $abilityRange = ($configured && ($configured['min'] ?? 0) > 0)
                ? $configured
                : (self::ABILITY_RANGES[$tier] ?? ['min' => 5, 'max' => 35]);

            $npcClubs   = $this->npcClubRepository->findByLeague($league);
            $clubsData  = [];
            $allClubIds = [];

            // Add the player's club to the fixture list if it belongs to this league
            if ($club->getCurrentLeague()?->getId()->toBinary() === $league->getId()->toBinary()) {
                $allClubIds[] = (string) $club->getId();
            }

            foreach ($npcClubs as $npcClub) {
                $allClubIds[] = (string) $npcClub->getId();
                $totalPlayers = random_int((int) $tierConf['playerMin'], (int) $tierConf['playerMax']);
                $foreignPct   = (int) $tierConf['foreignPercent'];

                // Distribute squad size across positions using PoolConfig weights
                $posCounts = $this->distributeByPosition($totalPlayers, $poolConfig);
                $players   = [];

                foreach ($posCounts as $posValue => $posTotal) {
                    $position      = PlayerPosition::from($posValue);
                    $foreignCount  = (int) round($posTotal * $foreignPct / 100);
                    $domesticCount = $posTotal - $foreignCount;

                    $domestic = $this->playerRepository->findForWorldInitByPositionAndNationality(
                        $abilityRange['min'], $abilityRange['max'], $position, $nationality, $domesticCount
                    );
                    if (count($domestic) < $domesticCount) {
                        $deficit  = $domesticCount - count($domestic);
                        $extra    = $this->playerRepository->findForeignForWorldInitByPosition(
                            $abilityRange['min'], $abilityRange['max'], '__none__', $position, $deficit
                        );
                        $domestic = array_merge($domestic, $extra);
                    }

                    $foreign = $this->playerRepository->findForeignForWorldInitByPosition(
                        $abilityRange['min'], $abilityRange['max'], $nationality, $position, $foreignCount
                    );
                    if (count($foreign) < $foreignCount) {
                        $deficit = $foreignCount - count($foreign);
                        $extra   = $this->playerRepository->findForWorldInitByPositionAndNationality(
                            $abilityRange['min'], $abilityRange['max'], $position, $nationality, $deficit
                        );
                        $foreign = array_merge($foreign, $extra);
                    }

                    $players = array_merge($players, $domestic, $foreign);
                }

                // Doctrine identity map ensures same-player objects are identical references;
                // array_unique with SORT_REGULAR safely deduplicates by object reference.
                $players = array_values(array_unique($players, SORT_REGULAR));
                $managers = $this->staffRepository->findInPoolByRoleRandom(StaffRole::MANAGER,   (int) $tierConf['managerCount']);
                $coaches  = $this->staffRepository->findInPoolByRoleRandom(StaffRole::COACH,     (int) $tierConf['coachCount']);
                $chairmen = $this->staffRepository->findInPoolByRoleRandom(StaffRole::CHAIRMAN,  (int) $tierConf['chairmanCount']);
                $staff    = array_merge($managers, $coaches, $chairmen);

                foreach ($players as $p) { $npcPlayerIds[] = (string) $p->getId(); }
                foreach ($staff   as $s) { $npcStaffIds[]  = (string) $s->getId(); }

                $clubsData[] = $this->buildClubSnapshot($npcClub, $players, $staff);
            }

            $fixtures      = $this->fixtureGenerationService->generate($allClubIds);
            $sponsorPot    = $this->rollLeagueSponsors($league, $gameConfig);
            $leaguesData[] = $this->buildLeagueSnapshot($league, $clubsData, $fixtures, $sponsorPot, $gameConfig);
        }

        $this->playerRepository->deleteByIds($npcPlayerIds);
        $this->staffRepository->deleteByIds($npcStaffIds);

        return ['leagues' => $leaguesData];
    }

    public function initialize(Club $club): array
    {
        $country     = $club->getCountry();
        $leaguesPack = $this->buildLeaguesPack($club, $country);

        $starterConfig = $this->starterConfigRepository->getConfig();
        $leagueRanges  = $starterConfig->getLeagueAbilityRanges();

        // AMP starter pack — use the same leagueAbilityRanges config as NPC clubs.
        // Convert the 2-letter country code to the nationality string used in the player pool.
        $ampLeagueTier = $club->getCurrentLeague()?->getTier() ?? 8;
        $ampRange      = $leagueRanges[$country][(string) $ampLeagueTier] ?? self::ABILITY_RANGES[$ampLeagueTier] ?? ['min' => 5, 'max' => 35];
        $ampNationality = ClubInitializationService::countryToNationality($country) ?? $country;

        // Distribute AMP starter squad across positions using PoolConfig weights
        $poolConfig = $this->poolConfigRepository->getConfig();
        $posCounts  = $this->distributeByPosition($starterConfig->getStarterPlayerCount(), $poolConfig);
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
            $this->fillStaffRole(StaffRole::MANAGER,              $starterConfig->getStarterManagerCount(),              $ampNationality),
            $this->fillStaffRole(StaffRole::COACH,                $starterConfig->getStarterCoachCount(),                $ampNationality),
            $this->fillStaffRole(StaffRole::DIRECTOR_OF_FOOTBALL, $starterConfig->getStarterDirectorOfFootballCount(),   $ampNationality),
            $this->fillStaffRole(StaffRole::FACILITY_MANAGER,     $starterConfig->getStarterFacilityManagerCount(),      $ampNationality),
            $this->fillStaffRole(StaffRole::CHAIRMAN,             $starterConfig->getStarterChairmanCount(),             $ampNationality),
        );

        $ampScouts = $this->scoutRepository->findInPool($starterConfig->getStarterScoutCount(), nationality: $ampNationality);
        if (count($ampScouts) < $starterConfig->getStarterScoutCount()) {
            $deficit    = $starterConfig->getStarterScoutCount() - count($ampScouts);
            $ampScouts  = array_merge(
                $ampScouts,
                $this->scoutRepository->findInPool($deficit), // fallback: any nationality
            );
        }

        foreach ($ampPlayers as $p) { $p->setClub($club); }
        foreach ($ampStaff   as $s) { $s->setClub($club); }

        // Note: Doctrine wraps flush() in an implicit DB transaction on PostgreSQL.
        // NPC player/staff pool deletions already executed (DQL) inside buildLeaguesPack().
        // This flush commits AMP FK assignments + worldInitializedAt.
        $club->setWorldInitializedAt(new \DateTimeImmutable());
        $this->em->flush();

        return array_merge($leaguesPack, [
            'ampStarter' => [
                'players' => array_map(fn(Player $p) => $this->buildPlayerSnapshot($p), $ampPlayers),
                'staff'   => array_map(fn(Staff $s) => $this->buildStaffSnapshot($s), $ampStaff),
                'scouts'  => array_map(fn(Scout $s) => $this->buildScoutSnapshot($s), $ampScouts),
            ],
        ]);
    }

    /**
     * Distributes $total players across GK/DEF/MID/ATT using PoolConfig position weights.
     * Uses the largest-remainder method so the counts always sum exactly to $total.
     *
     * @return array<string, int>  Keys are PlayerPosition backed-enum values ('GK','DEF','MID','ATT')
     */
    private function distributeByPosition(int $total, PoolConfig $config): array
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

    private function buildLeagueSnapshot(League $league, array $clubsData, array $fixtures, int $sponsorPot, GameConfig $gameConfig): array
    {
        return [
            'id'                            => (string) $league->getId(),
            'tier'                          => $league->getTier(),
            'name'                          => $league->getName(),
            'country'                       => $league->getCountry(),
            'promotionSpots'                => $league->getPromotionSpots(),
            'reputationTier'                => $league->getLeagueReputationTier()?->value,
            'tvDeal'                        => $league->getTvDeal(),
            'sponsorPot'                    => $sponsorPot,
            'prizeMoney'                    => $league->getPrizeMoney(),
            'leaguePositionPot'             => $league->getLeaguePositionPot(),
            'leaguePositionDecreasePercent' => $gameConfig->getLeaguePositionDecreasePercent(),
            'clubs'                         => $clubsData,
            'fixtures'                      => $fixtures,
        ];
    }

    /**
     * Rolls sponsor pot for a league: randomises each LeagueSponsor's value within
     * the GameConfig band for its CompanySize, persists rolled values, returns the total.
     */
    private function rollLeagueSponsors(League $league, GameConfig $config): int
    {
        $total = 0;
        foreach ($league->getLeagueSponsors() as $ls) {
            [$min, $max] = match ($ls->getSponsor()->getSize()) {
                CompanySize::SMALL  => [$config->getSmallSponsorMin(),  $config->getSmallSponsorMax()],
                CompanySize::MEDIUM => [$config->getMediumSponsorMin(), $config->getMediumSponsorMax()],
                CompanySize::LARGE  => [$config->getLargeSponsorMin(),  $config->getLargeSponsorMax()],
            };
            $value = $max > $min ? random_int($min, $max) : $min;
            $ls->setRolledValue($value);
            $total += $value;
        }
        return $total;
    }

    private function buildClubSnapshot(NpcClub $club, array $players, array $staff): array
    {
        return [
            'id'             => (string) $club->getId(),
            'name'           => $club->getName(),
            'abbreviation'   => $club->getAbbreviation() ?? ClubInitializationService::generateAbbreviation($club->getName()),
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
            'id'            => (string) $player->getId(),
            'firstName'     => $player->getFirstName(),
            'lastName'      => $player->getLastName(),
            'position'      => $player->getPosition()->value,
            'nationality'   => $player->getNationality(),
            'dateOfBirth'   => $player->getDateOfBirth()->format('Y-m-d'),
            'contractValue' => $player->getContractValue(),
            'pace'          => $player->getPace(),
            'technical'   => $player->getTechnical(),
            'vision'      => $player->getVision(),
            'power'       => $player->getPower(),
            'stamina'     => $player->getStamina(),
            'heart'       => $player->getHeart(),
            'personality' => [
                'determination'   => $p->getDetermination(),
                'professionalism' => $p->getProfessionalism(),
                'ambition'        => $p->getAmbition(),
                'loyalty'         => $p->getLoyalty(),
                'adaptability'    => $p->getAdaptability(),
                'pressure'        => $p->getPressure(),
                'temperament'     => $p->getTemperament(),
                'consistency'     => $p->getConsistency(),
            ],
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
            'specialisms'     => $staff->getSpecialisms() ?? [],
        ];
    }

    /** Fetch up to $limit staff of $role matching $nationality; backfill with any-nationality if short. */
    private function fillStaffRole(StaffRole $role, int $limit, string $nationality): array
    {
        $results = $this->staffRepository->findInPoolByRoleRandom($role, $limit, $nationality);
        if (count($results) < $limit) {
            $deficit = $limit - count($results);
            $results = array_merge(
                $results,
                $this->staffRepository->findInPoolByRoleRandom($role, $deficit),
            );
        }
        return $results;
    }

    private function buildScoutSnapshot(Scout $scout): array
    {
        return [
            'id'          => (string) $scout->getId(),
            'name'        => $scout->getName(),
            'nationality' => $scout->getNationality() ?? '',
            'experience'  => $scout->getExperience(),
            'judgements'  => $scout->getJudgements(),
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
