<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\Repository\AgentRepository;
use App\Repository\LeagueRepository;
use App\Repository\PoolConfigRepository;
use App\Repository\StarterConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * Generates unattached players for a country's free transfer list (Courtney, 2026-11-27).
 *
 * Two consumers, one implementation:
 *  - every world pack set carries a list, generated with the set and served once by
 *    `POST /api/initialize/free-agents`, which the device loads straight into its free
 *    transfer list at new game;
 *  - `GET /api/players/free-agents` hands a fresh batch to the season transition.
 *
 * Rules:
 *  - **Nationality** is the pack country's, for every player. No foreignPercent.
 *  - **Ability** is weighted toward the bottom half of the pyramid. A tier is picked per
 *    player and the player is generated inside that tier's country band — the same
 *    `StarterPackService::resolveAbilityRange()` the starter squad and NPC clubs use, so the
 *    pool scales by country with everything else.
 *  - **No money.** Snapshots go through `WorldPackSnapshotBuilder::buildPlayerSnapshot()`,
 *    which strips `contractValue`; the device prices free agents like any pack player, at the
 *    tier named by each snapshot's `packTier`.
 *
 * Nothing is persisted and nothing is consumed, so a call cannot fail on supply.
 */
class FreeAgentGenerationService
{
    /** Hard ceiling on one batch, whatever config or the caller asks for. */
    public const MAX_BATCH = 300;

    public function __construct(
        private readonly WorldPackClubGenerationService $clubGenerator,
        private readonly WorldPackSnapshotBuilder $snapshotBuilder,
        private readonly StarterPackService $starterPackService,
        private readonly StarterConfigRepository $starterConfigRepository,
        private readonly PoolConfigRepository $poolConfigRepository,
        private readonly AgentRepository $agentRepository,
        private readonly LeagueRepository $leagueRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /** A world pack set's list, sized by StarterConfig::$freeAgentPackCount. */
    public function generateForPack(string $country): array
    {
        return $this->generate($country, $this->starterConfigRepository->getConfig()->getFreeAgentPackCount());
    }

    /**
     * Generates $count free agents for $country and returns their pack snapshots.
     *
     * @return list<array<string, mixed>>
     */
    public function generate(string $country, int $count): array
    {
        $count = max(0, min($count, self::MAX_BATCH));
        if ($count === 0) {
            return [];
        }

        $tiers = array_map(
            static fn($league) => $league->getTier(),
            $this->leagueRepository->findByCountry($country),
        );
        if ($tiers === []) {
            $tiers = [StarterPackService::DEFAULT_STARTER_TIER];
        }

        $upperPercent = $this->starterConfigRepository->getConfig()->getFreeAgentUpperTierPercent();
        $perTier      = self::allocate($count, self::tierWeights($tiers, $upperPercent));

        $nationality = ClubInitializationService::countryToNationality($country) ?? $country;
        $poolConfig  = $this->poolConfigRepository->getConfig();
        $agents      = $this->agentRepository->findAll();

        $snapshots = [];
        foreach ($perTier as $tier => $tierCount) {
            if ($tierCount <= 0) {
                continue;
            }
            $abilityRange = $this->starterPackService->resolveAbilityRange($country, (int) $tier);
            $abilityRange = ['min' => $abilityRange['min'], 'max' => $abilityRange['max']];

            $players = $this->clubGenerator->generatePlayers(
                ['playerMin' => $tierCount, 'playerMax' => $tierCount, 'foreignPercent' => 0],
                $abilityRange,
                $nationality,
                $poolConfig,
                $agents,
            );

            foreach ($players as $player) {
                /** @var Player $player */
                // `packTier`: the tier band the player was generated in. The device prices an
                // unpriced pack player against a tier's wage scale; a free agent has no club, so
                // this is the tier it is pitched at.
                $snapshots[] = $this->snapshotBuilder->buildPlayerSnapshot($player) + ['packTier' => (int) $tier];
            }
        }

        $this->logger->info('free_agents.generate.done', [
            'country'      => $country,
            'requested'    => $count,
            'generated'    => count($snapshots),
            'perTier'      => $perTier,
            'upperPercent' => $upperPercent,
        ]);

        return $snapshots;
    }

    /**
     * Share of the batch each tier gets, as fractions summing to 1.
     *
     * The pyramid is split in two: the bottom half (the larger half when the count is odd)
     * takes `100 - $upperPercent` of the batch, the top half takes `$upperPercent`. Within
     * each half the deeper tiers weigh more:
     *  - bottom half: weight = (half size) + (distance from the top of the half), a gentle
     *    slope, because those tiers are the pool's main market;
     *  - top half: weight = distance from tier 1 + 1, a steeper slope, so a handful of
     *    players still suit the top divisions.
     *
     * 8 tiers at 20%: t8 24.6%, t7 21.5%, t6 18.5%, t5 15.4%, t4 8%, t3 6%, t2 4%, t1 2%.
     * A one-tier country gets everything in its only tier.
     *
     * @param list<int> $tiers tier numbers, 1 = top division
     * @return array<int, float> tier => fraction
     */
    public static function tierWeights(array $tiers, int $upperPercent): array
    {
        $tiers = array_values(array_unique($tiers));
        sort($tiers);                                   // 1 (top) … N (bottom)
        $n = count($tiers);
        if ($n === 0) {
            return [];
        }
        if ($n === 1) {
            return [$tiers[0] => 1.0];
        }

        $upperShare = max(0, min(100, $upperPercent)) / 100;
        $topSize    = intdiv($n, 2);
        $top        = array_slice($tiers, 0, $topSize);
        $bottom     = array_slice($tiers, $topSize);
        $b          = count($bottom);

        $weights = [];

        $topRaw = [];
        foreach ($top as $i => $tier) {
            $topRaw[$tier] = $i + 1;                    // t1 = 1, deeper = heavier
        }
        $topSum = array_sum($topRaw) ?: 1;
        foreach ($topRaw as $tier => $w) {
            $weights[$tier] = $upperShare * $w / $topSum;
        }

        $bottomRaw = [];
        foreach ($bottom as $i => $tier) {
            $bottomRaw[$tier] = $b + $i + 1;           // gentle slope toward the bottom tier
        }
        $bottomSum = array_sum($bottomRaw) ?: 1;
        foreach ($bottomRaw as $tier => $w) {
            $weights[$tier] = (1 - $upperShare) * $w / $bottomSum;
        }

        ksort($weights);

        return $weights;
    }

    /**
     * Whole-player counts per tier from fractions, by largest remainder, so they sum to $count.
     *
     * @param array<int, float> $weights
     * @return array<int, int>
     */
    public static function allocate(int $count, array $weights): array
    {
        $counts     = [];
        $remainders = [];
        foreach ($weights as $tier => $w) {
            $exact             = $count * $w;
            $counts[$tier]     = (int) floor($exact);
            $remainders[$tier] = $exact - floor($exact);
        }

        $left = $count - array_sum($counts);
        arsort($remainders);
        foreach (array_keys($remainders) as $tier) {
            if ($left <= 0) {
                break;
            }
            $counts[$tier]++;
            $left--;
        }

        return $counts;
    }
}
