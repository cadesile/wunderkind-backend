<?php

namespace App\Service\MatchEngine;

use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Enum\Competition\MatchEngineIdentifier;
use App\Enum\PlayingStyle;
use App\Repository\TacticalAdvantageRepository;
use App\Service\Appearance\SeededRng;

/**
 * Type A (baseline/default). Aggregates each side's snapshot into a strength score,
 * simulates a fixed number of "chances" across the match, each going to one side
 * (weighted by relative strength) and converting to a goal with a flat probability —
 * this is the "controlled variance for upsets" the spec asks for: a weaker side still
 * gets chances proportional to its own strength, not zero, so upsets are rare but real.
 *
 * Seeded via SeededRng::hashId($fixture->getId()) — the same deterministic-by-id
 * convention AppearanceGeneratorService already uses — so a re-run against the same
 * fixture is reproducible (useful for tests), even though match outcome need not be
 * literally deterministic across different fixtures the way appearance generation is.
 *
 * Reuses the existing admin-configurable TacticalAdvantage matchup table (style vs
 * opponentStyle -> multiplier) rather than inventing new tactical logic — that table
 * already drives the single-player on-device engine's style-vs-style advantage (served
 * to the client via SyncService's tacticalMatrix), this just applies the same data
 * server-side, since Cup fixtures are resolved server-side against frozen snapshots. Only
 * playingStyle affects resolution; formation is captured in the snapshot for narrative/
 * display purposes but there's no formation-vs-formation table in this codebase to apply.
 */
class DeterministicEngine implements MatchEngineInterface
{
    private const CHANCES_PER_MATCH = 10;
    private const CHANCE_CONVERSION_RATE = 0.28;
    private const CARD_CHANCE = 0.06;
    /** Strength floor so a snapshot with no readable player attributes doesn't divide by zero / auto-lose. */
    private const DEFAULT_STRENGTH = 10.0;

    public function __construct(
        private readonly TacticalAdvantageRepository $tacticalAdvantageRepository,
    ) {}

    public function supports(MatchEngineIdentifier $identifier): bool
    {
        return $identifier === MatchEngineIdentifier::DETERMINISTIC;
    }

    public function resolve(CompetitionEntrant $home, CompetitionEntrant $away, CompetitionFixture $fixture): MatchEngineResult
    {
        $rng = new SeededRng(SeededRng::hashId($fixture->getId()->toRfc4122()));

        $homeStrength = $this->strengthOf($home);
        $awayStrength = $this->strengthOf($away);

        $homeStyle = $this->styleOf($home);
        $awayStyle = $this->styleOf($away);
        if ($homeStyle !== null && $awayStyle !== null) {
            $homeStrength *= $this->tacticalAdvantageRepository->findMultiplier($homeStyle, $awayStyle);
            $awayStrength *= $this->tacticalAdvantageRepository->findMultiplier($awayStyle, $homeStyle);
        }

        $homeGoals = 0;
        $awayGoals = 0;
        $eventLog  = [];

        for ($chance = 0; $chance < self::CHANCES_PER_MATCH; $chance++) {
            $minute = max(1, min(90, (int) round(($chance + $rng->next()) * (90 / self::CHANCES_PER_MATCH))));

            $team = $rng->weightedPick(
                ['home', 'away'],
                [max(1, (int) round($homeStrength)), max(1, (int) round($awayStrength))],
            );
            $entrant = $team === 'home' ? $home : $away;

            if ($rng->chance(self::CHANCE_CONVERSION_RATE)) {
                if ($team === 'home') {
                    $homeGoals++;
                } else {
                    $awayGoals++;
                }
                $eventLog[] = ['minute' => $minute, 'type' => 'goal', 'team' => $team, 'scorer' => $this->pickScorer($rng, $entrant)];
            } elseif ($rng->chance(self::CARD_CHANCE)) {
                $eventLog[] = ['minute' => $minute, 'type' => 'yellow_card', 'team' => $team];
            }
        }

        usort($eventLog, static fn (array $a, array $b) => $a['minute'] <=> $b['minute']);

        return new MatchEngineResult($homeGoals, $awayGoals, $eventLog);
    }

    private function strengthOf(CompetitionEntrant $entrant): float
    {
        $players = $entrant->getSnapshotJson()['players'] ?? [];
        if ($players === []) {
            return self::DEFAULT_STRENGTH;
        }

        $sum   = 0.0;
        $count = 0;
        foreach ($players as $player) {
            if (isset($player['currentAbility']) && is_numeric($player['currentAbility'])) {
                $sum += (float) $player['currentAbility'];
                $count++;
            }
        }

        return $count > 0 ? $sum / $count : self::DEFAULT_STRENGTH;
    }

    private function styleOf(CompetitionEntrant $entrant): ?PlayingStyle
    {
        $value = $entrant->getSnapshotJson()['club']['playingStyle'] ?? null;

        return is_string($value) ? PlayingStyle::tryFrom($value) : null;
    }

    private function pickScorer(SeededRng $rng, CompetitionEntrant $entrant): ?string
    {
        $players = $entrant->getSnapshotJson()['players'] ?? [];
        if ($players === []) {
            return null;
        }

        $player = $rng->pick($players);

        return is_array($player) ? ($player['id'] ?? null) : null;
    }
}
