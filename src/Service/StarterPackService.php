<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Enum\PlayerPosition;
use App\Enum\StaffRole;
use App\Exception\InsufficientStarterPoolException;
use App\Repository\PlayerRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\ScoutRepository;
use App\Repository\StaffRepository;
use App\Repository\StarterConfigRepository;
use App\Service\ClubInitializationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class StarterPackService
{
    public function __construct(
        private readonly PlayerRepository           $playerRepository,
        private readonly StaffRepository            $staffRepository,
        private readonly ScoutRepository            $scoutRepository,
        private readonly StarterConfigRepository    $starterConfigRepository,
        private readonly PoolConfigRepository       $poolConfigRepository,
        private readonly WorldPackSnapshotBuilder   $snapshotBuilder,
        private readonly EntityManagerInterface     $em,
        private readonly LoggerInterface            $logger,
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

        $this->logger->info('starter_pack.initialize.start', [
            'clubId'           => (string) $club->getId(),
            'country'          => $country,
            'ampLeagueTier'    => $ampLeagueTier,
            'ampRangeSource'   => isset($leagueRanges[$country][(string) $ampLeagueTier]) ? 'starterConfig.leagueAbilityRanges' : (isset(WorldInitializationService::ABILITY_RANGES[$ampLeagueTier]) ? 'WorldInitializationService::ABILITY_RANGES' : 'hardcoded_fallback_5_35'),
            'ampRangeMin'      => $ampRange['min'],
            'ampRangeMax'      => $ampRange['max'],
            'ampNationality'   => $ampNationality,
            'countryResolvedToNationality' => ClubInitializationService::countryToNationality($country) !== null,
            'starterPlayerCount' => $starterConfig->getStarterPlayerCount(),
        ]);

        $poolConfig = $this->poolConfigRepository->getConfig();
        $posCounts  = $this->snapshotBuilder->distributeByPosition(
            $starterConfig->getStarterPlayerCount(),
            $poolConfig
        );
        $ampPlayers = [];

        foreach ($posCounts as $posValue => $count) {
            $position    = PlayerPosition::from($posValue);
            $posPlayers  = $this->playerRepository->findForWorldInitByPositionAndNationality(
                $ampRange['min'], $ampRange['max'], $position, $ampNationality, $count
            );
            $nativeFound = count($posPlayers);
            $foreignFound = 0;
            if (count($posPlayers) < $count) {
                $deficit    = $count - count($posPlayers);
                $extra      = $this->playerRepository->findForeignForWorldInitByPosition(
                    $ampRange['min'], $ampRange['max'], '__none__', $position, $deficit
                );
                $foreignFound = count($extra);
                $posPlayers = array_merge($posPlayers, $extra);
            }
            $this->logger->info('starter_pack.initialize.position_draw', [
                'clubId'       => (string) $club->getId(),
                'position'     => $posValue,
                'requested'    => $count,
                'nativeFound'  => $nativeFound,
                'foreignFound' => $foreignFound,
                'totalFound'   => count($posPlayers),
            ]);
            $ampPlayers = array_merge($ampPlayers, $posPlayers);
        }

        // Deduplicate using identity: Doctrine returns same PHP object for same row,
        // and mock objects have unique spl_object_id values. Without dedup, double-remove throws.
        $seen = [];
        $ampPlayers = array_values(array_filter($ampPlayers, function(Player $p) use (&$seen) {
            $id = spl_object_id($p);
            if (isset($seen[$id])) return false;
            $seen[$id] = true;
            return true;
        }));

        if ($ampPlayers === []) {
            $this->logger->warning('starter_pack.initialize.empty_player_draw', [
                'clubId'         => (string) $club->getId(),
                'country'        => $country,
                'ampNationality' => $ampNationality,
                'ampRangeMin'    => $ampRange['min'],
                'ampRangeMax'    => $ampRange['max'],
            ]);
            // Bail before touching staff/scouts or the pool at all — nothing consumed, nothing
            // flushed, Club::$starterInitializedAt untouched, so this is safely retriable. See
            // InsufficientStarterPoolException's own docblock for why this matters.
            throw new InsufficientStarterPoolException($ampNationality);
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

        // Deduplicate using identity: Doctrine returns same PHP object for same row,
        // and mock objects have unique spl_object_id values.
        $seen = [];
        $ampScouts = array_values(array_filter($ampScouts, function(Scout $s) use (&$seen) {
            $id = spl_object_id($s);
            if (isset($seen[$id])) return false;
            $seen[$id] = true;
            return true;
        }));

        // Build snapshots before deletion (entities must exist to serialise)
        $playerSnapshots = array_map(
            fn(Player $p) => $this->snapshotBuilder->buildPlayerSnapshot($p),
            $ampPlayers
        );
        $staffSnapshots = array_map(
            fn(Staff $s) => $this->snapshotBuilder->buildStaffSnapshot($s),
            $ampStaff
        );
        $scoutSnapshots = array_map(
            fn(Scout $s) => $this->snapshotBuilder->buildScoutSnapshot($s),
            $ampScouts
        );

        // Consume pool entities — delete from DB, frontend stores snapshots locally
        foreach ($ampPlayers as $p) { $this->em->remove($p); }
        foreach ($ampStaff   as $s) { $this->em->remove($s); }

        $club->setStarterInitializedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->logger->info('starter_pack.initialize.success', [
            'clubId'      => (string) $club->getId(),
            'playerCount' => count($ampPlayers),
            'staffCount'  => count($ampStaff),
            'scoutCount'  => count($ampScouts),
        ]);

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
