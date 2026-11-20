<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Repository\AgentRepository;
use App\Repository\LeagueRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\StarterConfigRepository;
use App\Service\ClubInitializationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class StarterPackService
{
    /**
     * Last-resort tier for a starter squad when the country's pyramid cannot be read.
     *
     * NOT THE NORMAL PATH ANY MORE. A brand-new club has no league yet when its pack is
     * built, and this used to be taken literally as tier 8 — fine while every country had
     * eight tiers, badly wrong once they do not. In a 3-tier country the squad was drawn
     * at the tier-8 band (10-25 ability) and then placed in tier 3, whose NPCs were
     * generated at 55-75: a founder roughly 45 points behind every opponent, with nothing
     * logged and the diagnostic reporting it as correct.
     *
     * initialize() now resolves the country's real bottom tier instead. This remains only
     * for a country with no leagues at all, where there is nothing better to say.
     */
    public const DEFAULT_STARTER_TIER = 8;

    public function __construct(
        private readonly StarterConfigRepository       $starterConfigRepository,
        private readonly PoolConfigRepository          $poolConfigRepository,
        private readonly WorldPackSnapshotBuilder      $snapshotBuilder,
        private readonly WorldPackClubGenerationService $clubGenerator,
        private readonly AgentRepository                $agentRepository,
        private readonly EntityManagerInterface        $em,
        private readonly LoggerInterface               $logger,
        private readonly LeagueRepository              $leagueRepository,
    ) {}

    /**
     * Resolves the ability band a starter squad is drawn from, and reports WHICH source
     * supplied it. Public and separate from initialize() so StarterPackDiagnosticService
     * reports the same band the real draw will use — an operator-facing check that
     * re-derived this would be free to drift from the code it is checking.
     *
     * @return array{min: int, max: int, source: string}
     */
    public function resolveAbilityRange(string $country, int $tier): array
    {
        $leagueRanges = $this->starterConfigRepository->getConfig()->getLeagueAbilityRanges();

        if (isset($leagueRanges[$country][(string) $tier])) {
            $raw    = $leagueRanges[$country][(string) $tier];
            $source = 'starterConfig.leagueAbilityRanges';
        } elseif (isset(WorldInitializationService::ABILITY_RANGES[$tier])) {
            $raw    = WorldInitializationService::ABILITY_RANGES[$tier];
            $source = 'WorldInitializationService::ABILITY_RANGES';
        } else {
            $raw    = ['min' => 5, 'max' => 35];
            $source = 'hardcoded_fallback_5_35';
        }

        return ['min' => (int) $raw['min'], 'max' => (int) $raw['max'], 'source' => $source];
    }

    /**
     * Generates one starter squad for ($country, $tier): players, staff and scouts, fresh and
     * in memory, and returns their snapshots.
     *
     * ── IT GENERATES. IT DOES NOT DRAW FROM THE POOL ─────────────────────────────────────
     *
     * This used to be a draw: it queried the shared `player`/`staff` tables by ability band,
     * position, age floor and nationality, then `em->remove()`d what it took. That made a
     * founder's squad depend on a population it had nothing to do with, and the dependency
     * only ever failed in one direction — a country could have warm world packs and a pool too
     * thin to furnish a squad, so game init refused at its first step with a `412` and no
     * client-side escape. Pre-generating sets made it worse, because a thin draw then sat in
     * READY stock waiting for a real player rather than failing in front of the one person who
     * triggered it.
     *
     * NPC clubs have never had that problem: WorldPackClubGenerationService builds every
     * player, coach, chairman, DoF, facility manager and scout a club needs from tier config,
     * with no pool-table dependency at all. This now does the same thing through that same
     * service, so there is ONE implementation of "generate a squad" and the founder's club is
     * built the way every club around it is.
     *
     * **Nothing is persisted and nothing is consumed**, so this cannot fail on supply, there is
     * no longer anything to flush atomically with it, and it is safe to call speculatively.
     *
     * ── NOT PRICED AT ALL, LIKE AN NPC CLUB (v11, 2026-11-18) ────────────────────────────
     *
     * This squad used to be priced at generation, with country and tier passed to the wage
     * model, to close the one gap where a founder's squad sat outside the economy. That gap is
     * closed the other way round now: NOBODY is priced at generation. A starter pack reaches
     * the client through `WorldPackSnapshotBuilder::buildPlayerSnapshot()`, which strips
     * `contractValue`, so the device prices this squad from its division's published wage
     * scale at initialisation — the same resolution, from the same figures, one step later and
     * off a cached payload.
     *
     * The founder's squad and an NPC club's squad therefore agree by construction rather than
     * by two call sites remembering to pass the same arguments, which is what the old note
     * above was really guarding.
     *
     * `$clubReputation` is 0 for a brand-new club, which is what `Club::$reputation` defaults
     * to and the honest value for a club with no history. It no longer reaches a wage — it is
     * kept because it is logged with the rest of the generation inputs, and because the caller
     * already has it.
     *
     * @return array{players: array, staff: array, scouts: array}
     */
    public function generatePack(string $country, int $tier, int $clubReputation = 0): array
    {
        $starterConfig = $this->starterConfigRepository->getConfig();
        $poolConfig     = $this->poolConfigRepository->getConfig();
        $abilityRange   = $this->resolveAbilityRange($country, $tier);
        $nationality    = ClubInitializationService::countryToNationality($country) ?? $country;

        $squadConf = $this->starterSquadConfig($starterConfig);

        $this->logger->info('starter_pack.generate.start', [
            'country'            => $country,
            'ampLeagueTier'      => $tier,
            'ampRangeSource'     => $abilityRange['source'],
            'ampRangeMin'        => $abilityRange['min'],
            'ampRangeMax'        => $abilityRange['max'],
            'ampNationality'     => $nationality,
            'starterPlayerCount' => $starterConfig->getStarterPlayerCount(),
            'clubReputation'     => $clubReputation,
        ]);

        // Every agent, as the tier build does. selectBoundedAgentPool() exists to stop the
        // DISTINCT agents in a pack scaling with the pool, and a starter squad of a handful of
        // players can surface at most that handful, so there is nothing here for it to bound.
        $agents = $this->agentRepository->findAll();

        $players = $this->clubGenerator->generatePlayers(
            $squadConf,
            $abilityRange,
            $nationality,
            $poolConfig,
            $agents,
        );

        // ⚠️ **$abilityRange IS THE PLAYER BAND AND NO LONGER REACHES STAFF (A25, 2026-11-20,
        // owner ruling).** These two lines read
        //
        //     // Same $abilityRange as the squad above — a founder's staff are at the
        //     // founder's level.
        //
        // and that is what replaced it: a founder's staff ARE still at the founder's level,
        // but off the authored per-country/per-tier STAFF table resolved inside
        // StaffGenerationService from $country and $tier, not off the squad's band.
        //
        // Lending the player band was a workaround for there being no staff table at all, and
        // it was wrong in a way that could not be tuned out: the band is applied to POTENTIAL
        // (PlayerGenerationService's parameter is $potentialRange), so a founder's squad lands
        // BELOW it while a coach handed the same band is at that figure from day one. The two
        // sides of every staff-vs-player comparison were therefore different quantities. See
        // StaffAbilityRangeService for the table, the measured seed and the scale ruling.
        $staff  = $this->clubGenerator->generateStaff($squadConf, $nationality, $country, $tier);
        $scouts = $this->clubGenerator->generateScouts($squadConf, $nationality, $country, $tier);

        $pack = [
            'players' => array_map(fn(Player $p) => $this->snapshotBuilder->buildPlayerSnapshot($p), $players),
            'staff'   => array_map(fn(Staff $s) => $this->snapshotBuilder->buildStaffSnapshot($s), $staff),
            'scouts'  => array_map(fn(Scout $sc) => $this->snapshotBuilder->buildScoutSnapshot($sc), $scouts),
        ];

        $this->logger->info('starter_pack.generate.done', [
            'country'     => $country,
            'playerCount' => count($pack['players']),
            'staffCount'  => count($pack['staff']),
            'scoutCount'  => count($pack['scouts']),
        ]);

        return $pack;
    }

    /**
     * StarterConfig's starter counts in the `npcSquadConfig` tier shape
     * WorldPackClubGenerationService takes, so the founder's club goes through the same
     * generator as every NPC club around it rather than a parallel implementation.
     *
     * Two mappings are decisions rather than transcription:
     *
     * - **`playerMin` = `playerMax` = `starterPlayerCount`.** An NPC squad size is ROLLED
     *   between a min and a max; a starter squad is an authored, exact number an operator sets
     *   on the Starter config screen and expects to get.
     * - **`foreignPercent` = 0.** The draw this replaced asked for home-nationality players and
     *   reached for foreigners only to cover a SHORTFALL. Generation has no shortfall, so the
     *   faithful equivalent of "natives, then foreigners if you must" is all natives. A founder
     *   fielding an all-home squad is also the intended starting position; signing abroad is
     *   something the player does later.
     *
     * @return array<string, int>
     */
    private function starterSquadConfig(\App\Entity\StarterConfig $config): array
    {
        $players = $config->getStarterPlayerCount();

        return [
            'playerMin'               => $players,
            'playerMax'               => $players,
            'foreignPercent'          => 0,
            'managerCount'            => $config->getStarterManagerCount(),
            'coachCount'              => $config->getStarterCoachCount(),
            'chairmanCount'           => $config->getStarterChairmanCount(),
            'directorOfFootballCount' => $config->getStarterDirectorOfFootballCount(),
            'facilityManagerCount'    => $config->getStarterFacilityManagerCount(),
            'scoutCount'              => $config->getStarterScoutCount(),
        ];
    }

    /**
     * Resolves the tier a brand-new club in $country should be drawn at: its own league if
     * it has one, else the country's real bottom division — which is where
     * maybeAutoAssignLeague() is about to put it.
     *
     * Assuming 8 here is what produced squads 45 ability points below their opponents in any
     * country with a shallower pyramid; see DEFAULT_STARTER_TIER.
     */
    public function resolveStarterTier(?string $country, ?Club $club = null): int
    {
        return $club?->getCurrentLeague()?->getTier()
            ?? $this->leagueRepository->findLowestTierForCountry((string) $country)?->getTier()
            ?? self::DEFAULT_STARTER_TIER;
    }

    public function initialize(Club $club): array
    {
        $country       = $club->getCountry();
        $ampLeagueTier = $this->resolveStarterTier($country, $club);

        $pack = $this->generatePack((string) $country, $ampLeagueTier, $club->getReputation());

        // Still stored on the club, and still the only copy — the snapshots are generated in
        // memory and nothing else holds them, so a response lost in transit would otherwise
        // leave the club initialised with nothing and a permanent 409 on every retry. What has
        // changed is that this is no longer racing a pool consume: nothing was removed, so the
        // flush has only the club's own two writes to land.
        $club->setStarterPackJson($pack);
        $club->setStarterInitializedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->logger->info('starter_pack.initialize.success', [
            'clubId'      => (string) $club->getId(),
            'playerCount' => count($pack['players']),
            'staffCount'  => count($pack['staff']),
            'scoutCount'  => count($pack['scouts']),
        ]);

        return $pack;
    }

}
