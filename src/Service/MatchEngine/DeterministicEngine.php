<?php

namespace App\Service\MatchEngine;

use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\GameConfig;
use App\Enum\Competition\MatchEngineIdentifier;
use App\Enum\PlayingStyle;
use App\Repository\GameConfigRepository;
use App\Repository\TacticalAdvantageRepository;
use App\Service\Appearance\SeededRng;

/**
 * Type A (baseline/default). A direct port of wunderkind-app's single-player match
 * engine (`src/engine/ResultsEngine.ts` + the card half of `SimulationService.ts`) —
 * a "dominance" score per side (ability/morale/condition, tactics, manager, personality,
 * cohesion) drives a Poisson-distributed goal count, then goals/assists/cards/ratings are
 * distributed across the XI. Every optional input the frontend engine itself treats as
 * "absent = neutral" (manager motivation, low-urgency, cohesion, suspensions,
 * out-of-position) is read from the entrant snapshot when present and defaults to the
 * same neutral value the frontend uses when it isn't — see
 * docs/api/competition-registration-snapshot-v2.md for the additive fields a client can
 * send to get more of this model driven by real data instead of neutral defaults.
 *
 * One deliberate divergence from the TS source: every "random" draw here goes through
 * this fixture's seeded `SeededRng` (seeded via `SeededRng::hashId($fixture->getId())`,
 * same convention as the rest of this codebase) rather than `Math.random()` — the
 * frontend doesn't need reproducibility, this engine does (see
 * DeterministicEngineTest::testSameFixtureIdProducesTheSameResultEveryTime()).
 *
 * Another deliberate adaptation: `ResultsEngine.distributeGoalsAndAssists()` only tracks
 * aggregate goal/assist *counts* per player for the whole match — event strings never
 * pair a specific assist to a specific goal's minute. This engine's `eventLog` shape
 * (one row per goal, `{minute, scorer, assist}`) predates this port and is relied on by
 * MatchNarrativeGeneratorService, the admin bracket UI, and existing tests, so each goal
 * is paired with an assist (from the same position-weighted pool) at generation time
 * instead of only producing match-wide totals.
 *
 * Cup knockout resolution: every Competition fixture in this codebase is a single-elimination
 * bracket match, which can never end level — a 90-minute draw triggers a real extra-time
 * simulation (30 more minutes, same dominance ratio, genuine additional goal events), and
 * only falls to a penalty shootout if still level after that. The shootout winner is decided
 * by a literal coin flip (not a per-kick model) with a fabricated but plausible scoreline
 * for display — see resolve()'s inline comments and MatchEngineResult's wentToExtraTime/
 * wentToPenalties/penaltyHomeScore/penaltyAwayScore fields.
 */
class DeterministicEngine implements MatchEngineInterface
{
    private const OUT_OF_POSITION_PENALTY = 0.8;
    private const NEUTRAL_MANAGER_MOTIVATION = 60.0;
    private const LOW_URGENCY_SHARPNESS_MULTIPLIER = 0.95;
    /** Default manager ability when no MANAGER role entry exists in the snapshot's staff[]. */
    private const DEFAULT_MANAGER_ABILITY = 50.0;

    public function __construct(
        private readonly TacticalAdvantageRepository $tacticalAdvantageRepository,
        private readonly GameConfigRepository $gameConfigRepository,
        private readonly MatchNarrativeGeneratorService $narrativeGenerator,
    ) {}

    public function supports(MatchEngineIdentifier $identifier): bool
    {
        return $identifier === MatchEngineIdentifier::DETERMINISTIC;
    }

    public function resolve(CompetitionEntrant $home, CompetitionEntrant $away, CompetitionFixture $fixture): MatchEngineResult
    {
        return $this->resolveMatch($home, $away, $fixture, allowDraws: false);
    }

    public function resolveFriendly(CompetitionEntrant $home, CompetitionEntrant $away, CompetitionFixture $fixture): MatchEngineResult
    {
        return $this->resolveMatch($home, $away, $fixture, allowDraws: true);
    }

    private function resolveMatch(CompetitionEntrant $home, CompetitionEntrant $away, CompetitionFixture $fixture, bool $allowDraws = false): MatchEngineResult
    {
        $rngSeed = SeededRng::hashId($fixture->getId()->toRfc4122());
        $rng     = new SeededRng($rngSeed);
        $config  = $this->gameConfigRepository->getConfig();

        [$homeDominance, $homeDominanceAudit] = $this->calculateDominance($home, $away, $config);
        [$awayDominance, $awayDominanceAudit] = $this->calculateDominance($away, $home, $config);

        $totalDominance = $homeDominance + $awayDominance;
        $homeRatio      = $totalDominance > 0 ? $homeDominance / $totalDominance : 0.5;
        $awayRatio      = 1 - $homeRatio;

        $variation          = ($rng->next() - 0.5) * 1.5; // -0.75..+0.75
        $expectedTotalGoals = 3.0 + $variation;
        $homeExpectedGoals  = $expectedTotalGoals * $homeRatio;
        $awayExpectedGoals  = $expectedTotalGoals * $awayRatio;

        $homeGoals        = $this->poissonRandom($rng, $homeExpectedGoals);
        $awayGoals        = $this->poissonRandom($rng, $awayExpectedGoals);
        $regHomeGoals     = $homeGoals;
        $regAwayGoals     = $awayGoals;

        [$homeGoalEvents, $homeRegGoalAudit] = $this->distributeGoalsAndAssists($rng, $home, 'home', $homeGoals);
        [$awayGoalEvents, $awayRegGoalAudit] = $this->distributeGoalsAndAssists($rng, $away, 'away', $awayGoals);

        // Knockout fixture — a level score after 90 minutes can't stand; extra time gets a
        // genuine chance to settle it before falling back to penalties. Friendlies allow draws.
        $wentToExtraTime  = false;
        $wentToPenalties  = false;
        $penaltyHomeScore = null;
        $penaltyAwayScore = null;
        $extraTimeAudit   = [
            'triggered'          => false,
            'expectedTotalGoals' => null,
            'homeExpectedGoals'  => null,
            'awayExpectedGoals'  => null,
            'homeGoals'          => null,
            'awayGoals'          => null,
            'homeGoalEvents'     => [],
            'awayGoalEvents'     => [],
        ];
        $penaltyShootoutAudit = [
            'triggered'        => false,
            'homeWinsShootout' => null,
            'winnerPens'       => null,
            'loserPens'        => null,
            'penaltyHomeScore' => null,
            'penaltyAwayScore' => null,
        ];

        if (!$allowDraws && $homeGoals === $awayGoals) {
            $wentToExtraTime = true;

            $etExpectedGoals     = $expectedTotalGoals * (30 / 90);
            $etHomeExpectedGoals = $etExpectedGoals * $homeRatio;
            $etAwayExpectedGoals = $etExpectedGoals * $awayRatio;
            $etHomeGoals         = $this->poissonRandom($rng, $etHomeExpectedGoals);
            $etAwayGoals         = $this->poissonRandom($rng, $etAwayExpectedGoals);

            [$etHomeGoalEvents, $etHomeGoalAudit] = $this->distributeGoalsAndAssists($rng, $home, 'home', $etHomeGoals, $this->rollExtraTimeGoalMinute(...));
            [$etAwayGoalEvents, $etAwayGoalAudit] = $this->distributeGoalsAndAssists($rng, $away, 'away', $etAwayGoals, $this->rollExtraTimeGoalMinute(...));

            $homeGoalEvents = array_merge($homeGoalEvents, $etHomeGoalEvents);
            $awayGoalEvents = array_merge($awayGoalEvents, $etAwayGoalEvents);

            $homeGoals += $etHomeGoals;
            $awayGoals += $etAwayGoals;

            $extraTimeAudit = [
                'triggered'          => true,
                'expectedTotalGoals' => $etExpectedGoals,
                'homeExpectedGoals'  => $etHomeExpectedGoals,
                'awayExpectedGoals'  => $etAwayExpectedGoals,
                'homeGoals'          => $etHomeGoals,
                'awayGoals'          => $etAwayGoals,
                'homeGoalEvents'     => $etHomeGoalAudit['events'],
                'awayGoalEvents'     => $etAwayGoalAudit['events'],
            ];

            if ($homeGoals === $awayGoals) {
                $wentToPenalties = true;

                $homeWinsShootout = $rng->chance(0.5);
                $winnerPens       = 5;
                $loserPens        = 5 - $rng->pick([1, 2, 3]);
                $penaltyHomeScore = $homeWinsShootout ? $winnerPens : $loserPens;
                $penaltyAwayScore = $homeWinsShootout ? $loserPens : $winnerPens;

                $penaltyShootoutAudit = [
                    'triggered'        => true,
                    'homeWinsShootout' => $homeWinsShootout,
                    'winnerPens'       => $winnerPens,
                    'loserPens'        => $loserPens,
                    'penaltyHomeScore' => $penaltyHomeScore,
                    'penaltyAwayScore' => $penaltyAwayScore,
                ];
            }
        }

        $homeGoalStats = $this->tallyGoalStats($homeGoalEvents);
        $awayGoalStats = $this->tallyGoalStats($awayGoalEvents);

        [$homeCardEvents, $homeCardStats, $homeCardAudit] = $this->distributeCards($rng, $home, 'home', $config, $homeGoals - $awayGoals);
        [$awayCardEvents, $awayCardStats, $awayCardAudit] = $this->distributeCards($rng, $away, 'away', $config, $awayGoals - $homeGoals);

        $eventLog = array_merge($homeGoalEvents, $awayGoalEvents, $homeCardEvents, $awayCardEvents);
        usort($eventLog, static fn (array $a, array $b) => $a['minute'] <=> $b['minute']);

        [$homeLineup, $homeRatingsAudit] = $this->calculatePerformance($rng, $home, $homeGoals, $awayGoals, $homeGoalStats, $homeCardStats, $config);
        [$awayLineup, $awayRatingsAudit] = $this->calculatePerformance($rng, $away, $awayGoals, $homeGoals, $awayGoalStats, $awayCardStats, $config);

        $narrativePayload = $this->narrativeGenerator->generate(
            $fixture,
            $home,
            $away,
            $eventLog,
            $homeLineup,
            $awayLineup,
            $wentToExtraTime,
            $wentToPenalties,
            $penaltyHomeScore,
            $penaltyAwayScore,
        );

        $computationAudit = [
            'schemaVersion'    => 1,
            'engine'           => [
                'requestedEngine' => MatchEngineIdentifier::DETERMINISTIC->value,
                'resolvedBy'      => MatchEngineIdentifier::DETERMINISTIC->value,
                'delegatedFrom'   => null,
                'fallbackReason'  => null,
            ],
            'fixture'          => [
                'fixtureId'  => $fixture->getId()->toRfc4122(),
                'rngSeed'    => $rngSeed,
                'allowDraws' => $allowDraws,
            ],
            'entrants'         => [
                'home' => [
                    'entrantId'       => (string) $home->getId(),
                    'clubId'          => (string) $home->getClub()->getId(),
                    'clubName'        => $this->clubOf($home)['name'] ?? $home->getClub()->getName(),
                    'seed'            => $home->getSeed(),
                    'snapshotVersion' => $home->getSnapshotVersion(),
                ],
                'away' => [
                    'entrantId'       => (string) $away->getId(),
                    'clubId'          => (string) $away->getClub()->getId(),
                    'clubName'        => $this->clubOf($away)['name'] ?? $away->getClub()->getName(),
                    'seed'            => $away->getSeed(),
                    'snapshotVersion' => $away->getSnapshotVersion(),
                ],
            ],
            'config'           => [
                'yellowCardBaseChance'        => $config->getYellowCardBaseChance(),
                'redCardBaseChance'           => $config->getRedCardBaseChance(),
                'temperamentCardScale'        => $config->getTemperamentCardScale(),
                'losingTeamCardMultiplierMax' => $config->getLosingTeamCardMultiplierMax(),
                'losingTeamCardGoalDiffCap'   => $config->getLosingTeamCardGoalDiffCap(),
            ],
            'dominance'        => [
                'home'           => $homeDominanceAudit,
                'away'           => $awayDominanceAudit,
                'homeDominance'  => $homeDominance,
                'awayDominance'  => $awayDominance,
                'totalDominance' => $totalDominance,
                'homeRatio'      => $homeRatio,
                'awayRatio'      => $awayRatio,
            ],
            'regulation'       => [
                'baseExpectedTotalGoals' => 3.0,
                'variationRoll'          => $variation,
                'expectedTotalGoals'     => $expectedTotalGoals,
                'homeExpectedGoals'      => $homeExpectedGoals,
                'awayExpectedGoals'      => $awayExpectedGoals,
                'homeGoals'              => $regHomeGoals,
                'awayGoals'              => $regAwayGoals,
            ],
            'extraTime'        => $extraTimeAudit,
            'penaltyShootout'  => $penaltyShootoutAudit,
            'goalDistribution' => [
                'home' => $homeRegGoalAudit,
                'away' => $awayRegGoalAudit,
            ],
            'cardDistribution' => [
                'home' => $homeCardAudit,
                'away' => $awayCardAudit,
            ],
            'playerRatings'    => [
                'home' => $homeRatingsAudit,
                'away' => $awayRatingsAudit,
            ],
            'outcome'          => [
                'homeScore'        => $homeGoals,
                'awayScore'        => $awayGoals,
                'wentToExtraTime'  => $wentToExtraTime,
                'wentToPenalties'  => $wentToPenalties,
                'penaltyHomeScore' => $penaltyHomeScore,
                'penaltyAwayScore' => $penaltyAwayScore,
                'resolutionMethod' => $wentToPenalties ? 'penalties' : ($wentToExtraTime ? 'extra_time' : 'regulation'),
            ],
        ];

        return new MatchEngineResult(
            $homeGoals,
            $awayGoals,
            $eventLog,
            $narrativePayload,
            $homeLineup,
            $awayLineup,
            $wentToExtraTime,
            $wentToPenalties,
            $penaltyHomeScore,
            $penaltyAwayScore,
            $computationAudit,
        );
    }

    /**
     * Port of ResultsEngine.ts's calculateDominance() — no RNG draws, pure function of snapshot + config.
     *
     * @return array{0: float, 1: array<string, mixed>}
     */
    private function calculateDominance(CompetitionEntrant $entrant, CompetitionEntrant $opponent, GameConfig $config): array
    {
        $players       = $this->playersOf($entrant);
        $style         = $this->styleOf($entrant);
        $opponentStyle = $this->styleOf($opponent);
        $boostedAttrs  = $style !== null ? ($config->getPlayingStyleInfluence()[$style->value] ?? []) : [];

        $score       = 0.0;
        $playerAudit = [];
        foreach ($players as $player) {
            $ability         = is_numeric($player['currentAbility'] ?? null) ? (float) $player['currentAbility'] : 50.0;
            $morale          = is_numeric($player['morale'] ?? null) ? (float) $player['morale'] : 50.0;
            $condition       = is_numeric($player['condition'] ?? null) ? (float) $player['condition'] : 80.0;
            $conditionFactor = 0.7 + ($condition / 100) * 0.3;
            $moraleFactor    = 1 + ($morale - 50) / 100;
            $baseScore       = $this->baseScore($player);
            $playerScore     = $baseScore;

            $outOfPosition = ($player['outOfPosition'] ?? false) === true;
            if ($outOfPosition) {
                $playerScore *= self::OUT_OF_POSITION_PENALTY;
            }

            $boostedAvg      = $boostedAttrs !== [] ? $this->averageOf($player, $boostedAttrs) : 0.0;
            $styleAttrFactor = $boostedAttrs !== [] ? 1 + 0.10 * ($boostedAvg / 100) : 1.0;
            if ($boostedAttrs !== []) {
                $playerScore *= $styleAttrFactor;
            }

            $score += $playerScore;

            $playerAudit[] = [
                'id'                      => is_string($player['id'] ?? null) ? $player['id'] : null,
                'name'                    => is_string($player['name'] ?? null) ? $player['name'] : null,
                'position'                => is_string($player['position'] ?? null) ? $player['position'] : null,
                'currentAbility'          => $ability,
                'condition'               => $condition,
                'morale'                  => $morale,
                'conditionFactor'         => $conditionFactor,
                'moraleFactor'            => $moraleFactor,
                'baseScore'               => $baseScore,
                'outOfPosition'           => $outOfPosition,
                'outOfPositionMultiplier' => $outOfPosition ? self::OUT_OF_POSITION_PENALTY : 1.0,
                'boostedAttrsAverage'     => $boostedAvg,
                'styleAttrMultiplier'     => $styleAttrFactor,
                'finalScore'              => $playerScore,
            ];
        }

        $rawPlayerScoreSum = $score;

        $tacticalMultiplier = 1.0;
        if ($style !== null && $opponentStyle !== null) {
            $tacticalMultiplier = $this->tacticalAdvantageRepository->findMultiplier($style, $opponentStyle);
            $score *= $tacticalMultiplier;
        }
        $scoreAfterTactics = $score;

        $managerAbility   = $this->managerAbilityOf($entrant);
        $rawMotivation    = $this->managerMotivationOf($entrant);
        $motivation       = $rawMotivation ?? self::NEUTRAL_MANAGER_MOTIVATION;
        $motivationFactor = max(0.5, min(1.5, 0.5 + 0.5 * ($motivation / self::NEUTRAL_MANAGER_MOTIVATION)));
        $managerMultiplier = 1 + ($managerAbility / 100) * 0.05 * $motivationFactor;
        $score *= $managerMultiplier;
        $scoreAfterManager = $score;

        $lowUrgency = ($this->clubOf($entrant)['lowUrgency'] ?? false) === true;
        if ($lowUrgency) {
            $score *= self::LOW_URGENCY_SHARPNESS_MULTIPLIER;
        }
        $scoreAfterLowUrgency = $score;

        $personalitySum   = 0.0;
        $personalityCount = 0;
        foreach ($players as $player) {
            $personality = is_array($player['personality'] ?? null) ? $player['personality'] : [];
            $prof        = is_numeric($personality['professionalism'] ?? null) ? (float) $personality['professionalism'] : 10.0;
            $cons        = is_numeric($personality['consistency'] ?? null) ? (float) $personality['consistency'] : 10.0;
            $personalitySum += ($prof + $cons) / 2;
            $personalityCount++;
        }
        $avgPersonality        = $personalityCount > 0 ? $personalitySum / $personalityCount : 10.5;
        $personalityMultiplier = 1 + (($avgPersonality - 10.5) / 10.5) * 0.05;
        $score *= $personalityMultiplier;
        $scoreAfterPersonality = $score;

        $cohesion = $this->clubOf($entrant)['cohesion'] ?? null;
        $cohesionAudit = [
            'rawValue'               => is_numeric($cohesion) ? (float) $cohesion : null,
            'passAccuracyMultiplier' => 1.0,
            'errorRateMultiplier'    => 1.0,
        ];
        if (is_numeric($cohesion)) {
            $mods   = $this->cohesionMatchModifiers((float) $cohesion);
            $score *= $mods['passAccuracyMultiplier'];
            $score /= $mods['errorRateMultiplier'];
            $cohesionAudit = [
                'rawValue'               => (float) $cohesion,
                'passAccuracyMultiplier' => $mods['passAccuracyMultiplier'],
                'errorRateMultiplier'    => $mods['errorRateMultiplier'],
            ];
        }

        $audit = [
            'playingStyle'         => $style?->value,
            'opponentPlayingStyle' => $opponentStyle?->value,
            'boostedAttributes'    => $boostedAttrs,
            'players'              => $playerAudit,
            'rawPlayerScoreSum'    => $rawPlayerScoreSum,
            'tacticalAdvantage'    => [
                'multiplier' => $tacticalMultiplier,
                'scoreAfter' => $scoreAfterTactics,
            ],
            'manager'              => [
                'ability'             => $managerAbility,
                'motivation'          => $motivation,
                'motivationDefaulted' => $rawMotivation === null,
                'motivationFactor'    => $motivationFactor,
                'multiplier'          => $managerMultiplier,
                'scoreAfter'          => $scoreAfterManager,
            ],
            'lowUrgency'           => [
                'active'     => $lowUrgency,
                'multiplier' => $lowUrgency ? self::LOW_URGENCY_SHARPNESS_MULTIPLIER : 1.0,
                'scoreAfter' => $scoreAfterLowUrgency,
            ],
            'personality'          => [
                'playerCount'       => $personalityCount,
                'averageTraitScore' => $avgPersonality,
                'multiplier'        => $personalityMultiplier,
                'scoreAfter'        => $scoreAfterPersonality,
            ],
            'cohesion'             => $cohesionAudit,
            'finalDominance'       => $score,
        ];

        return [$score, $audit];
    }

    private function baseScore(array $player): float
    {
        $ability   = is_numeric($player['currentAbility'] ?? null) ? (float) $player['currentAbility'] : 50.0;
        $morale    = is_numeric($player['morale'] ?? null) ? (float) $player['morale'] : 50.0;
        $condition = is_numeric($player['condition'] ?? null) ? (float) $player['condition'] : 80.0;

        $conditionFactor = 0.7 + ($condition / 100) * 0.3;
        $moraleFactor    = 1 + ($morale - 50) / 100;

        return $ability * $conditionFactor * $moraleFactor;
    }

    /** @param string[] $attrs */
    private function averageOf(array $player, array $attrs): float
    {
        $sum = 0.0;
        foreach ($attrs as $attr) {
            $sum += is_numeric($player[$attr] ?? null) ? (float) $player[$attr] : 0.0;
        }

        return $attrs === [] ? 0.0 : $sum / count($attrs);
    }

    /** Port of ResultsEngine.ts's poissonRandom(). */
    private function poissonRandom(SeededRng $rng, float $mean): int
    {
        $l = exp(-$mean);
        $k = 0;
        $p = 1.0;
        do {
            $k++;
            $p *= $rng->next();
        } while ($p > $l);

        return $k - 1;
    }

    /**
     * Port of ResultsEngine.ts's distributeGoalsAndAssists(), adapted to emit one eventLog
     * entry per goal (with a paired assist and its own rolled minute) rather than only
     * match-wide totals — see class docblock.
     *
     * @return array{0: list<array{minute:int,type:'goal',team:string,scorer:?string,assist:?string}>, 1: array<string, mixed>}
     */
    private function distributeGoalsAndAssists(SeededRng $rng, CompetitionEntrant $entrant, string $team, int $goals, ?\Closure $rollMinute = null): array
    {
        if ($goals <= 0) {
            return [[], [
                'goalsRequested' => 0,
                'goalWeights'    => [],
                'assistWeights'  => [],
                'events'         => [],
            ]];
        }

        $players = $this->playersOf($entrant);
        if ($players === []) {
            return [[], [
                'goalsRequested' => $goals,
                'goalWeights'    => [],
                'assistWeights'  => [],
                'events'         => [],
            ]];
        }

        $goalWeights   = [];
        $assistWeights = [];
        foreach ($players as $player) {
            $id = $player['id'] ?? null;
            if (!is_string($id)) {
                continue;
            }
            $ovr = is_numeric($player['currentAbility'] ?? null) ? (float) $player['currentAbility'] : 50.0;
            $pos = $player['position'] ?? null;

            $goalWeights[$id]   = ($pos === 'ATT' ? 4 : ($pos === 'MID' ? 2 : ($pos === 'DEF' ? 0.8 : 0.1))) * ($ovr / 100);
            $assistWeights[$id] = ($pos === 'MID' ? 3 : ($pos === 'ATT' ? 2 : ($pos === 'DEF' ? 0.8 : 0.1))) * ($ovr / 100);
        }

        $events     = [];
        $eventAudit = [];
        for ($g = 0; $g < $goals; $g++) {
            $scorer = $this->weightedPickId($rng, $goalWeights);

            $adjustedAssistWeights = $assistWeights;
            if ($scorer !== null && isset($adjustedAssistWeights[$scorer])) {
                $adjustedAssistWeights[$scorer] *= 0.1; // heavily downweight self-assist
            }
            $assist = $this->weightedPickId($rng, $adjustedAssistWeights);
            if ($assist === $scorer) {
                $assist = null;
            }

            $minute       = $rollMinute !== null ? $rollMinute($rng) : $this->rollGoalMinute($rng);
            $events[]     = ['minute' => $minute, 'type' => 'goal', 'team' => $team, 'scorer' => $scorer, 'assist' => $assist];
            $eventAudit[] = [
                'minute'       => $minute,
                'scorerId'     => $scorer,
                'scorerWeight' => $scorer !== null ? ($goalWeights[$scorer] ?? null) : null,
                'assistId'     => $assist,
                'assistWeight' => $assist !== null ? ($adjustedAssistWeights[$assist] ?? null) : null,
            ];
        }

        return [$events, [
            'goalsRequested'    => $goals,
            'totalGoalWeight'   => array_sum($goalWeights),
            'totalAssistWeight' => array_sum($assistWeights),
            'goalWeights'       => $goalWeights,
            'assistWeights'     => $assistWeights,
            'events'            => $eventAudit,
        ]];
    }

    /** @param array<string, float> $weights */
    private function weightedPickId(SeededRng $rng, array $weights): ?string
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            return null;
        }

        $roll = $rng->next() * $total;
        foreach ($weights as $id => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $id;
            }
        }

        $ids = array_keys($weights);

        return $ids[count($ids) - 1];
    }

    /** Port of ResultsEngine.ts's rollGoalMinute() — ~45% first half, ~2.5% first-half stoppage, ~47.5% second half, ~5% second-half stoppage. */
    private function rollGoalMinute(SeededRng $rng): int
    {
        $r = $rng->next();
        if ($r < 0.45) {
            return (int) floor($rng->next() * 45) + 1;
        }
        if ($r < 0.475) {
            return (int) floor($rng->next() * 5) + 46;
        }
        if ($r < 0.95) {
            return (int) floor($rng->next() * 44) + 46;
        }

        return (int) floor($rng->next() * 5) + 91;
    }

    /**
     * Minute roller for the 30-minute extra-time period (91-122') — deliberately simpler
     * than rollGoalMinute()'s four-band regulation model (no need to mirror a two-45-minute
     * structure for two 15-minute ET halves): ~90% uniform across 91-119', ~10% in a short
     * 120-122' stoppage window.
     */
    private function rollExtraTimeGoalMinute(SeededRng $rng): int
    {
        if ($rng->next() < 0.9) {
            return (int) floor($rng->next() * 29) + 91;
        }

        return (int) floor($rng->next() * 3) + 120;
    }

    /** @param list<array{minute:int,type:'goal',team:string,scorer:?string,assist:?string}> $goalEvents
     *  @return array<string, array{goals:int,assists:int}> */
    private function tallyGoalStats(array $goalEvents): array
    {
        $stats = [];
        foreach ($goalEvents as $event) {
            if ($event['scorer'] !== null) {
                $stats[$event['scorer']]['goals'] = ($stats[$event['scorer']]['goals'] ?? 0) + 1;
            }
            if ($event['assist'] !== null) {
                $stats[$event['assist']]['assists'] = ($stats[$event['assist']]['assists'] ?? 0) + 1;
            }
        }

        return $stats;
    }

    /**
     * Port of distributeCards() (ResultsEngine.ts) + its SimulationService.ts call site
     * (config values, cohesion risk bonus) — restructured from "per fixed chance-slot" to
     * per eligible outfield player, once each, matching the source exactly.
     *
     * @return array{0: list<array{minute:int,type:string,team:string,player:string}>, 1: array<string, array{yellowCards:int,redCards:int}>, 2: array<string, mixed>}
     */
    private function distributeCards(SeededRng $rng, CompetitionEntrant $entrant, string $team, GameConfig $config, int $goalDifference): array
    {
        $scale         = $config->getTemperamentCardScale();
        $maxMultiplier = $config->getLosingTeamCardMultiplierMax();
        $diffCap       = $config->getLosingTeamCardGoalDiffCap();

        $cohesion           = $this->clubOf($entrant)['cohesion'] ?? null;
        $cohesionMultiplier = 1 + (is_numeric($cohesion) ? $this->cohesionMatchModifiers((float) $cohesion)['cardRiskBonus'] : 0.0);

        $events     = [];
        $stats      = [];
        $playerRolls = [];
        foreach ($this->playersOf($entrant) as $player) {
            $id = $player['id'] ?? null;
            if (!is_string($id)) {
                continue;
            }
            if (($player['isActive'] ?? true) === false) {
                continue;
            }
            if ((int) ($player['suspendedMatches'] ?? 0) > 0) {
                continue;
            }
            if (($player['position'] ?? null) === 'GK') {
                continue;
            }

            $personality  = is_array($player['personality'] ?? null) ? $player['personality'] : [];
            $temperament  = is_numeric($personality['temperament'] ?? null) ? (float) $personality['temperament'] : 10.0;
            $tempMultiplier = 1 + ((20 - max(1, min(20, $temperament))) / 20) * $scale;

            $deficit          = min(abs(min($goalDifference, 0)), $diffCap);
            $resultMultiplier = 1 + ($maxMultiplier - 1) * ($diffCap > 0 ? $deficit / $diffCap : 0);

            $yellowProb = $config->getYellowCardBaseChance() * $tempMultiplier * $resultMultiplier * $cohesionMultiplier;
            $redProb    = $config->getRedCardBaseChance() * $tempMultiplier * $resultMultiplier * $cohesionMultiplier;

            $rolledYellow = $rng->chance($yellowProb);
            $rolledRed    = $rng->chance($redProb);
            $minute       = (int) floor($rng->next() * 90) + 1;

            $awardedCard = null;
            if ($rolledRed) {
                $events[]    = ['minute' => $minute, 'type' => 'red_card', 'team' => $team, 'player' => $id];
                $stats[$id]  = ['yellowCards' => 0, 'redCards' => 1];
                $awardedCard = 'red_card';
            } elseif ($rolledYellow) {
                $events[]    = ['minute' => $minute, 'type' => 'yellow_card', 'team' => $team, 'player' => $id];
                $stats[$id]  = ['yellowCards' => 1, 'redCards' => 0];
                $awardedCard = 'yellow_card';
            }

            $playerRolls[] = [
                'id'               => $id,
                'name'             => is_string($player['name'] ?? null) ? $player['name'] : null,
                'position'         => is_string($player['position'] ?? null) ? $player['position'] : null,
                'temperament'      => $temperament,
                'tempMultiplier'   => $tempMultiplier,
                'resultMultiplier' => $resultMultiplier,
                'yellowProb'       => $yellowProb,
                'redProb'          => $redProb,
                'rolledYellow'     => $rolledYellow,
                'rolledRed'        => $rolledRed,
                'minute'           => $minute,
                'awardedCard'      => $awardedCard,
            ];
        }

        return [$events, $stats, [
            'goalDifference'     => $goalDifference,
            'cohesionMultiplier' => $cohesionMultiplier,
            'playerRolls'        => $playerRolls,
        ]];
    }

    /**
     * Port of calculatePerformance()/calculatePlayerPerformance() (ResultsEngine.ts).
     *
     * @param array<string, array{goals?:int,assists?:int}> $goalStats
     * @param array<string, array{yellowCards:int,redCards:int}> $cardStats
     * @return array{0: list<array{id:?string,name:?string,position:?string,appearance:?array<string, mixed>,goals:int,assists:int,yellowCards:int,redCards:int,rating:float}>, 1: list<array<string, mixed>>}
     */
    private function calculatePerformance(SeededRng $rng, CompetitionEntrant $entrant, int $teamScore, int $opponentScore, array $goalStats, array $cardStats, GameConfig $config): array
    {
        $players = $this->playersOf($entrant);
        usort($players, static fn (array $a, array $b) => (
            (is_numeric($b['currentAbility'] ?? null) ? (float) $b['currentAbility'] : 0.0)
            <=> (is_numeric($a['currentAbility'] ?? null) ? (float) $a['currentAbility'] : 0.0)
        ));
        $teamSize = max(1, count($players));

        $style        = $this->styleOf($entrant);
        $boostedAttrs = $style !== null ? ($config->getPlayingStyleInfluence()[$style->value] ?? []) : [];

        $lineup       = [];
        $ratingsAudit = [];
        foreach ($players as $rank => $player) {
            $id      = is_string($player['id'] ?? null) ? $player['id'] : null;
            $goals   = $id !== null ? ($goalStats[$id]['goals'] ?? 0) : 0;
            $assists = $id !== null ? ($goalStats[$id]['assists'] ?? 0) : 0;
            $yellow  = $id !== null ? ($cardStats[$id]['yellowCards'] ?? 0) : 0;
            $red     = $id !== null ? ($cardStats[$id]['redCards'] ?? 0) : 0;

            $rankRatio = ($teamSize - $rank) / $teamSize;
            $base      = 4.5 + $rankRatio * 3.0;

            $resultBonus = $teamScore > $opponentScore ? 1.0 : ($teamScore < $opponentScore ? -1.0 : 0.0);
            $perfBonus   = $goals * 0.5 + $assists * 0.3;

            $styleBonus = $boostedAttrs !== [] ? ($this->averageOf($player, $boostedAttrs) / 100) * 0.5 : 0.0;

            $personality = is_array($player['personality'] ?? null) ? $player['personality'] : [];
            $consistency = is_numeric($personality['consistency'] ?? null) ? (float) $personality['consistency'] : 10.0;
            $temperament = is_numeric($personality['temperament'] ?? null) ? (float) $personality['temperament'] : 10.0;
            $pressure    = is_numeric($personality['pressure'] ?? null) ? (float) $personality['pressure'] : 10.0;

            $varianceScore = ($consistency + $temperament + $pressure) / 3;
            $noiseRange    = 1.5 - (($varianceScore - 1) / 19) * 1.2;
            $noise         = ($rng->next() - 0.5) * 2 * $noiseRange;

            $raw    = $base + $resultBonus + $perfBonus + $styleBonus + $noise;
            $rating = round(max(1.0, min(10.0, $raw)), 1);

            $lineup[] = [
                'id'          => $id,
                'name'        => is_string($player['name'] ?? null) ? $player['name'] : null,
                'position'    => is_string($player['position'] ?? null) ? $player['position'] : null,
                'appearance'  => is_array($player['appearance'] ?? null) ? $player['appearance'] : null,
                'goals'       => $goals,
                'assists'     => $assists,
                'yellowCards' => $yellow,
                'redCards'    => $red,
                'rating'      => $rating,
            ];

            $ratingsAudit[] = [
                'id'            => $id,
                'name'          => is_string($player['name'] ?? null) ? $player['name'] : null,
                'position'      => is_string($player['position'] ?? null) ? $player['position'] : null,
                'abilityRank'   => $rank + 1,
                'rankRatio'     => $rankRatio,
                'baseRating'    => $base,
                'resultBonus'   => $resultBonus,
                'goals'         => $goals,
                'assists'       => $assists,
                'perfBonus'     => $perfBonus,
                'styleBonus'    => $styleBonus,
                'consistency'   => $consistency,
                'temperament'   => $temperament,
                'pressure'      => $pressure,
                'varianceScore' => $varianceScore,
                'noiseRange'    => $noiseRange,
                'noiseRoll'     => $noise,
                'rawRating'     => $raw,
                'clampedRating' => $rating,
            ];
        }

        return [$lineup, $ratingsAudit];
    }

    /** Port of CohesionEngine.ts's getCohesionMatchModifiers() — only the fields this engine reads. */
    private function cohesionMatchModifiers(float $cohesion): array
    {
        if ($cohesion < 30) {
            return ['passAccuracyMultiplier' => 0.88, 'errorRateMultiplier' => 1.12, 'cardRiskBonus' => 0.06];
        }
        if ($cohesion < 55) {
            return ['passAccuracyMultiplier' => 0.97, 'errorRateMultiplier' => 1.04, 'cardRiskBonus' => 0.02];
        }
        if ($cohesion < 75) {
            return ['passAccuracyMultiplier' => 1.0, 'errorRateMultiplier' => 1.0, 'cardRiskBonus' => 0.0];
        }

        // 75-89 (united) and 90-100 (resolute) share the same values this engine reads.
        return ['passAccuracyMultiplier' => 1.05, 'errorRateMultiplier' => 1.0, 'cardRiskBonus' => 0.0];
    }

    private function managerAbilityOf(CompetitionEntrant $entrant): float
    {
        foreach ($entrant->getSnapshotJson()['staff'] ?? [] as $member) {
            if (is_array($member) && strtoupper((string) ($member['role'] ?? '')) === 'MANAGER' && is_numeric($member['ability'] ?? null)) {
                return (float) $member['ability'];
            }
        }

        return self::DEFAULT_MANAGER_ABILITY;
    }

    private function managerMotivationOf(CompetitionEntrant $entrant): ?float
    {
        $value = $this->clubOf($entrant)['managerMotivation'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /** @return array<int, array<string, mixed>> */
    private function playersOf(CompetitionEntrant $entrant): array
    {
        return array_values(array_filter($entrant->getSnapshotJson()['players'] ?? [], 'is_array'));
    }

    /** @return array<string, mixed> */
    private function clubOf(CompetitionEntrant $entrant): array
    {
        $club = $entrant->getSnapshotJson()['club'] ?? [];

        return is_array($club) ? $club : [];
    }

    private function styleOf(CompetitionEntrant $entrant): ?PlayingStyle
    {
        $value = $this->clubOf($entrant)['playingStyle'] ?? null;

        return is_string($value) ? PlayingStyle::tryFrom($value) : null;
    }
}
