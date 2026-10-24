<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\GameConfig;
use App\Entity\League;
use App\Enum\CompanySize;
use App\Entity\Agent;
use App\Repository\AgentRepository;
use App\Repository\GameConfigRepository;
use App\Repository\LeagueRepository;
use App\Repository\NpcClubRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\StarterConfigRepository;
use App\Service\ClubInitializationService;

class WorldInitializationService
{
    /**
     * Shape version of the world-pack payload. Bump this whenever **anything** in the
     * payload changes shape — WorldPackCacheService treats any cached row stamped with a
     * different version as a miss and rebuilds it. Not just the snapshot builders: the
     * league-level block in buildLeagueSnapshot() counts too, which is how version 3 came
     * to be needed.
     *
     * 1 — first version to guarantee a generated `personality` matrix on players,
     *     staff and scouts. Everything cached before this may carry all-10s
     *     defaults, and anything cached before 2026-08-23 has no `personality`
     *     key on staff/scout at all.
     *
     * ⚠️ Run `app:backfill-personalities` BEFORE bumping this, or the rebuilt
     * packs just re-serialise the same default matrices.
     *
     * 2 — NPC club `staff[]` may now include `director_of_football`/
     *     `facility_manager` entries, and each club snapshot gains a new
     *     `scouts[]` array (see `StarterConfig::$npcSquadConfig`'s
     *     directorOfFootballCount/facilityManagerCount/scoutCount). Packs
     *     cached under version 1 lack these entirely and are treated as a
     *     miss on next read.
     *
     * 3 — the league block carries `trophy` (the 16-bit trophy sprite's own
     *     TrophyConfig) instead of the retired `trophyImage`/`trophyColour`
     *     asset-slug pair. The code changed with the trophy-sprite work
     *     (`Version20261018202041`, 2026-10-18) but this constant was **not**
     *     bumped with it, so every pack cached before that date kept serving
     *     the retired shape — 84 of 85 rows on the production restore, and
     *     forever, since the cache has no TTL. Exactly the failure this
     *     constant exists to prevent, and the second time it has happened
     *     (see version 1's note about all-10s personality matrices).
     *
     * ⚠️ Bumping this makes every cached row a miss, so the next client request per
     * country/tier pays the rebuild (~1.5s for 8 clubs, ~3-4s for 18) on its critical
     * path. Run `app:worldpack:warm <CODE> --force` for each enabled country after
     * deploying a bump, so no player pays for it.
     */
    public const WORLD_PACK_VERSION = 3;

    /** Ability range by tier — indexes 1-8 */
    public const ABILITY_RANGES = [
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
        private readonly AgentRepository          $agentRepository,
        private readonly StarterConfigRepository  $starterConfigRepository,
        private readonly GameConfigRepository     $gameConfigRepository,
        private readonly PoolConfigRepository     $poolConfigRepository,
        private readonly FixtureGenerationService $fixtureGenerationService,
        private readonly WorldPackSnapshotBuilder $snapshotBuilder,
        private readonly WorldPackClubGenerationService $clubGenerator,
    ) {}

    /**
     * Builds the NPC club + player + fixture pack for a single league tier in a country.
     * Every player/coach/scout/DoF/chairman/facility-manager a club needs is generated
     * fresh, in memory, directly into the cache — never drawn from or deleted out of the
     * shared pool (see WorldPackClubGenerationService).
     *
     * @return array{id: string, tier: int, name: string, clubs: array, fixtures: array, ...}
     */
    public function buildTierPack(Club $club, string $country, int $tier): array
    {
        $starterConfig = $this->starterConfigRepository->getConfig();
        $poolConfig    = $this->poolConfigRepository->getConfig();
        $gameConfig    = $this->gameConfigRepository->getConfig();
        $agents        = $this->agentRepository->findAll(); // shared agent pool — many players may reference one

        $league = $this->leagueRepository->findByCountryAndTier($country, $tier);
        if ($league === null) {
            throw new \InvalidArgumentException("No league found for country={$country} tier={$tier}");
        }

        $nationality  = ClubInitializationService::countryToNationality($country) ?? $country;
        $tierConf     = $this->resolveTierConfig($tier);
        $abilityRange = $this->resolveAbilityRange($country, $tier);

        $npcClubs = $this->npcClubRepository->findByLeague($league);

        // Bound the agent pool to ~worldPackPlayersPerAgent players per agent for this tier,
        // so distinct agents surfaced don't scale with the whole pool.
        $avgSquad  = ((int) $tierConf['playerMin'] + (int) $tierConf['playerMax']) / 2;
        $estimated = (int) ceil(count($npcClubs) * $avgSquad);
        $agents    = $this->selectBoundedAgentPool($agents, $estimated, $starterConfig->getWorldPackPlayersPerAgent());

        $clubsData  = [];
        $allClubIds = [];

        if ($club->getCurrentLeague()?->getId()->toBinary() === $league->getId()->toBinary()) {
            $allClubIds[] = (string) $club->getId();
        }

        foreach ($npcClubs as $npcClub) {
            $allClubIds[] = (string) $npcClub->getId();

            $players = $this->clubGenerator->generatePlayers($tierConf, $abilityRange, $nationality, $poolConfig, $agents);
            $staff   = $this->clubGenerator->generateStaff($tierConf, $nationality);
            $scouts  = $this->clubGenerator->generateScouts($tierConf, $nationality);

            $clubsData[] = $this->clubGenerator->buildSnapshot($npcClub, $players, $staff, $scouts);
        }

        $fixtures   = $this->fixtureGenerationService->generate($allClubIds);
        $sponsorPot = $this->rollLeagueSponsors($league, $gameConfig);

        return $this->buildLeagueSnapshot($league, $clubsData, $fixtures, $sponsorPot, $gameConfig);
    }

    /** Public: also called by WorldPackTierAssemblyService when folding per-club async generation results into the final payload. */
    public function buildLeagueSnapshot(League $league, array $clubsData, array $fixtures, int $sponsorPot, GameConfig $gameConfig): array
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
            'trophy'                        => $league->getTrophy(),
            'clubs'                         => $clubsData,
            'fixtures'                      => $fixtures,
        ];
    }

    /**
     * Rolls sponsor pot for a league: randomises each LeagueSponsor's value within
     * the GameConfig band for its CompanySize, persists rolled values, returns the total.
     */
    /** Public: also called by WorldPackTierAssemblyService when folding per-club async generation results into the final payload. */
    public function rollLeagueSponsors(League $league, GameConfig $config): int
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

    /**
     * Returns a random subset of the agent pool sized so that, once players are
     * distributed across it, each agent represents ~$playersPerAgent players
     * (target = ceil($estimatedPlayers / $playersPerAgent), never more than the
     * pool holds). This is what keeps a world pack from surfacing one agent per
     * player: `assignAgents` then draws from this bounded subset, not the whole pool.
     * No-op on an empty pool or a non-positive estimate.
     *
     * @param Agent[] $agents
     * @return Agent[]
     */
    public function selectBoundedAgentPool(array $agents, int $estimatedPlayers, int $playersPerAgent): array
    {
        if ($agents === [] || $estimatedPlayers <= 0) {
            return $agents;
        }
        $target = min(count($agents), max(1, (int) ceil($estimatedPlayers / max(1, $playersPerAgent))));
        shuffle($agents);
        return array_slice($agents, 0, $target);
    }

    /**
     * Per-tier squad composition config, live from StarterConfig with a hardcoded
     * fallback — same source of truth buildTierPack() has always used. Public so the
     * async per-club Messenger handler (WarmWorldPackClubMessageHandler) can resolve
     * the same config a synchronous buildTierPack() call would, without duplicating
     * this lookup.
     */
    public function resolveTierConfig(int $tier): array
    {
        $npcConfig = $this->starterConfigRepository->getConfig()->getNpcSquadConfig();

        return $npcConfig[(string) $tier] ?? $this->defaultTierConfig($tier);
    }

    /**
     * Per-tier player ability range: StarterConfig's admin-configured league ranges
     * for this country/tier if set, else the hardcoded ABILITY_RANGES fallback. Public
     * for the same reason as resolveTierConfig() above.
     *
     * @return array{min: int, max: int}
     */
    public function resolveAbilityRange(string $country, int $tier): array
    {
        $leagueRanges = $this->starterConfigRepository->getConfig()->getLeagueAbilityRanges();
        $configured   = $leagueRanges[$country][(string) $tier] ?? null;

        return ($configured && ($configured['min'] ?? 0) > 0)
            ? ['min' => (int) $configured['min'], 'max' => (int) $configured['max']]
            : (self::ABILITY_RANGES[$tier] ?? ['min' => 5, 'max' => 35]);
    }

    private function defaultTierConfig(int $tier): array
    {
        return match (true) {
            $tier <= 2 => ['playerMin' => 20, 'playerMax' => 24, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'directorOfFootballCount' => 1, 'facilityManagerCount' => 1, 'scoutCount' => 2, 'foreignPercent' => 60],
            $tier <= 4 => ['playerMin' => 16, 'playerMax' => 20, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'directorOfFootballCount' => 0, 'facilityManagerCount' => 1, 'scoutCount' => 1, 'foreignPercent' => 25],
            $tier <= 6 => ['playerMin' => 13, 'playerMax' => 17, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'directorOfFootballCount' => 0, 'facilityManagerCount' => 0, 'scoutCount' => 1, 'foreignPercent' => 12],
            default    => ['playerMin' => 11, 'playerMax' => 14, 'managerCount' => 1, 'coachCount' => 1, 'chairmanCount' => 1, 'directorOfFootballCount' => 0, 'facilityManagerCount' => 0, 'scoutCount' => 0, 'foreignPercent' => 4],
        };
    }
}
