<?php

namespace App\Service\MatchEngine;

use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionFixture;
use App\Entity\GameEventTemplate;
use App\Enum\EventCategory;
use App\Repository\GameEventTemplateRepository;
use App\Service\Appearance\SeededRng;

/**
 * Server-side port of wunderkind-app's live match-commentary system —
 * `src/utils/narrativeEngine.ts` (the chain-graph walker) +
 * `src/utils/matchTimelineGenerator.ts` (the per-match orchestration) — so a Competition
 * result can carry a complete, ordered `MatchTimelineItem[]`-shaped timeline
 * (`narrativePayload`) instead of the client needing its own copy of the graph + real
 * match data to build one. Item shape matches wunderkind-app's
 * `TournamentMatchNarrativeEvent` (`docs/api/tournament-match-result-payload.md`): `side` is
 * `'HOME'|'AWAY'|null` (null for whole-match markers — half-time/full-time/penalty-shootout
 * summary), never a club id — the client has no local table to resolve one against for a
 * tournament opponent it may never have seen.
 *
 * `EventCategory::MATCH_NARRATIVE` rows are the chain-graph nodes: each candidate line of
 * a node (e.g. GOAL_ATTEMPT) is its own row, slugged `{NODE_TYPE}_{N}` (see
 * SeedMatchNarrativeTemplatesCommand), and `chainedEvents` entries are read as a
 * node-type graph edge (`nextEventSlug` = a node type, resolved to a random numbered row
 * at pick time) — a divergence from `chainedEvents`' NPC_INTERACTION weight-boost
 * semantics elsewhere in this codebase; see GameEventTemplate's docblock.
 *
 * Deliberately unrelated to `EventCategory::MATCH` (single-line ticker templates like
 * match_goal_1) — do not confuse the two.
 */
class MatchNarrativeGeneratorService
{
    /** Entry weights when filling a quiet stretch with no real event nearby. */
    private const FILLER_ENTRY_WEIGHTS = ['BUILDUP' => 35, 'GOAL_ATTEMPT' => 40, 'DISCIPLINE_FOUL' => 10];
    /** Entry weights when building the chain that leads up to (and ends on) a real goal. */
    private const GOAL_CHAIN_ENTRY_WEIGHTS = ['GOAL_ATTEMPT' => 70, 'CORNER_ATTEMPT' => 30];

    private const FILLER_MAX_STEPS = 3;
    private const GOAL_CHAIN_MAX_STEPS = 4;
    /** Roughly how far apart filler chains are spaced across a 90-minute match. */
    private const FILLER_INTERVAL_MINUTES = 12;

    public function __construct(
        private readonly GameEventTemplateRepository $templates,
    ) {}

    /**
     * @param list<array{minute:int,type:string,team?:string,scorer?:?string,assist?:?string,player?:?string}> $eventLog
     * @param list<array{id:?string,name:?string,position:?string,goals:int,assists:int,yellowCards:int,redCards:int,rating:float}> $homeLineup
     * @param list<array{id:?string,name:?string,position:?string,goals:int,assists:int,yellowCards:int,redCards:int,rating:float}> $awayLineup
     * @return list<array{minute:int,isKeyEvent:bool,eventType:?string,text:string,playerA:?string,playerB:?string,side:?string}>
     */
    public function generate(
        CompetitionFixture $fixture,
        CompetitionEntrant $home,
        CompetitionEntrant $away,
        array $eventLog,
        array $homeLineup,
        array $awayLineup,
        bool $wentToExtraTime = false,
        bool $wentToPenalties = false,
        ?int $penaltyHomeScore = null,
        ?int $penaltyAwayScore = null,
    ): array {
        $rows = $this->templates->findByCategory(EventCategory::MATCH_NARRATIVE);
        if ($rows === []) {
            return [];
        }

        $rowsByNodeType = $this->groupByNodeType($rows);
        $graph          = $this->buildGraph($rowsByNodeType);
        $rng            = new SeededRng(SeededRng::hashId($fixture->getId()->toRfc4122() . ':narrative'));

        $homeName = $this->clubName($home);
        $awayName = $this->clubName($away);

        $items = [];

        foreach ($eventLog as $event) {
            if ($event['type'] === 'goal') {
                $items = array_merge($items, $this->buildGoalChainItems($rng, $event, $home, $away, $homeLineup, $awayLineup, $homeName, $awayName, $rowsByNodeType, $graph));
            } elseif ($event['type'] === 'yellow_card' || $event['type'] === 'red_card') {
                $items = array_merge($items, $this->buildCardChainItems($rng, $event, $home, $away, $homeLineup, $awayLineup, $homeName, $awayName, $rowsByNodeType, $graph));
            }
        }

        $items = array_merge($items, $this->buildFillerItems($rng, $home, $away, $homeLineup, $awayLineup, $homeName, $awayName, $rowsByNodeType, $graph, $eventLog, $wentToExtraTime ? 120 : 90));

        $lastMinute = $wentToExtraTime ? 120 : 90;
        foreach ($eventLog as $event) {
            $lastMinute = max($lastMinute, (int) $event['minute']);
        }

        $items[] = ['minute' => 45, 'isKeyEvent' => false, 'eventType' => null, 'text' => 'Half-time.', 'playerA' => null, 'playerB' => null, 'side' => null];

        if ($wentToExtraTime) {
            $items[] = ['minute' => 90, 'isKeyEvent' => false, 'eventType' => null, 'text' => 'Full-time in normal time — this one\'s going to extra time!', 'playerA' => null, 'playerB' => null, 'side' => null];
            $items[] = ['minute' => $lastMinute + 1, 'isKeyEvent' => false, 'eventType' => null, 'text' => 'Full-time.', 'playerA' => null, 'playerB' => null, 'side' => null];
        } else {
            $items[] = ['minute' => $lastMinute + 1, 'isKeyEvent' => false, 'eventType' => null, 'text' => 'Full-time.', 'playerA' => null, 'playerB' => null, 'side' => null];
        }

        if ($wentToPenalties && $penaltyHomeScore !== null && $penaltyAwayScore !== null) {
            $winnerName = $penaltyHomeScore > $penaltyAwayScore ? $homeName : $awayName;
            $winnerPens = max($penaltyHomeScore, $penaltyAwayScore);
            $loserPens  = min($penaltyHomeScore, $penaltyAwayScore);
            $items[]    = [
                'minute'     => $lastMinute + 2,
                'isKeyEvent' => true,
                // Not 'GOAL'/'YELLOW_CARD'/'RED_CARD'/'FILLER' — a shootout belongs to neither
                // side specifically, same as the half-time/full-time markers above, so this
                // follows their eventType:null convention rather than inventing a new type
                // the client's TournamentMatchNarrativeEvent union doesn't expect.
                'eventType'  => null,
                'text'       => "{$winnerName} win {$winnerPens}-{$loserPens} on penalties!",
                'playerA'    => null,
                'playerB'    => null,
                'side'       => null,
            ];
        }

        usort($items, static fn (array $a, array $b) => $a['minute'] <=> $b['minute']);

        return array_values($items);
    }

    /** @param GameEventTemplate[] $rows @return array<string, GameEventTemplate[]> */
    private function groupByNodeType(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            if (preg_match('/^(.+)_(\d+)$/', $row->getSlug(), $matches) === 1) {
                $grouped[$matches[1]][] = $row;
            }
        }

        return $grouped;
    }

    /** @param array<string, GameEventTemplate[]> $rowsByNodeType @return array<string, list<string>> node type -> next node types */
    private function buildGraph(array $rowsByNodeType): array
    {
        $graph = [];
        foreach ($rowsByNodeType as $nodeType => $rows) {
            $next = [];
            foreach ($rows[0]->getChainedEvents() ?? [] as $link) {
                if (is_string($link['nextEventSlug'] ?? null) && isset($rowsByNodeType[$link['nextEventSlug']])) {
                    $next[] = $link['nextEventSlug'];
                }
            }
            $graph[$nodeType] = array_values(array_unique($next));
        }

        return $graph;
    }

    private function isScoringType(string $nodeType): bool
    {
        return str_ends_with($nodeType, '_SCORE');
    }

    /** @param array<string, list<string>> $graph @param array<string, int> $memo */
    private function minStepsToScore(string $nodeType, array $graph, array &$memo, int $depth = 0): int
    {
        if ($this->isScoringType($nodeType)) {
            return 0;
        }
        if ($depth > 10) {
            return PHP_INT_MAX;
        }
        if (isset($memo[$nodeType])) {
            return $memo[$nodeType];
        }

        $memo[$nodeType] = PHP_INT_MAX; // guard against cycles while computing
        $best            = PHP_INT_MAX;
        foreach ($graph[$nodeType] ?? [] as $next) {
            $steps = $this->minStepsToScore($next, $graph, $memo, $depth + 1);
            if ($steps !== PHP_INT_MAX) {
                $best = min($best, $steps + 1);
            }
        }

        $memo[$nodeType] = $best;

        return $best;
    }

    /** @param array<string, list<string>> $graph @return list<string> */
    private function walkFillerChain(SeededRng $rng, string $startType, array $graph, int $maxSteps): array
    {
        $chain   = [$startType];
        $current = $startType;
        for ($i = 1; $i < $maxSteps; $i++) {
            $candidates = array_values(array_filter($graph[$current] ?? [], fn (string $t) => !$this->isScoringType($t)));
            if ($candidates === []) {
                break;
            }
            $current  = $rng->pick($candidates);
            $chain[] = $current;
        }

        return $chain;
    }

    /** @param array<string, list<string>> $graph @return list<string> */
    private function walkScoringChain(SeededRng $rng, string $startType, array $graph, int $maxSteps): array
    {
        $memo    = [];
        $chain   = [$startType];
        $current = $startType;

        if ($this->isScoringType($current)) {
            return $chain;
        }

        for ($i = 1; $i < $maxSteps; $i++) {
            $budgetAfterPush = $maxSteps - $i - 1;
            $candidates      = array_values(array_filter(
                $graph[$current] ?? [],
                fn (string $t) => $this->minStepsToScore($t, $graph, $memo) <= $budgetAfterPush,
            ));
            if ($candidates === []) {
                break;
            }

            $scoring    = array_values(array_filter($candidates, fn (string $t) => $this->isScoringType($t)));
            $nonScoring = array_values(array_filter($candidates, fn (string $t) => !$this->isScoringType($t)));

            if ($scoring !== [] && $nonScoring !== []) {
                $current = $rng->chance(0.5) ? $rng->pick($scoring) : $rng->pick($nonScoring);
            } else {
                $current = $rng->pick($candidates);
            }

            $chain[] = $current;
            if ($this->isScoringType($current)) {
                break;
            }
        }

        return $chain;
    }

    /** @param array<string, GameEventTemplate[]> $rowsByNodeType */
    private function pickTemplateText(SeededRng $rng, string $nodeType, array $rowsByNodeType): ?string
    {
        $candidates = $rowsByNodeType[$nodeType] ?? [];
        if ($candidates === []) {
            return null;
        }

        $weights = array_map(static fn (GameEventTemplate $t) => max(1, $t->getWeight()), $candidates);

        /** @var GameEventTemplate $picked */
        $picked = $rng->weightedPick($candidates, $weights);

        return $picked->getBodyTemplate();
    }

    /** @param array<string, string> $entities */
    private function renderTemplate(string $template, array $entities, string $fallback): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($entities, $fallback) {
            return $entities[$m[1]] ?? $fallback;
        }, $template);
    }

    private function clubName(CompetitionEntrant $entrant): string
    {
        $name = $entrant->getSnapshotJson()['club']['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'The team';
    }

    /** @param list<array{id:?string,name:?string,position:?string,...}> $lineup */
    private function playerName(array $lineup, ?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        foreach ($lineup as $entry) {
            if ($entry['id'] === $id) {
                return $entry['name'];
            }
        }

        return null;
    }

    /** @param list<array{id:?string,name:?string,position:?string,...}> $lineup */
    private function randomPlayerName(SeededRng $rng, array $lineup, ?string $position = null, ?string $excludeId = null): ?string
    {
        $candidates = array_values(array_filter(
            $lineup,
            static fn (array $e) => $e['name'] !== null && $e['id'] !== $excludeId && ($position === null || $e['position'] === $position),
        ));
        if ($candidates === []) {
            $candidates = array_values(array_filter($lineup, static fn (array $e) => $e['name'] !== null && $e['id'] !== $excludeId));
        }
        if ($candidates === []) {
            return null;
        }

        return $rng->pick($candidates)['name'];
    }

    /** @param list<array{id:?string,name:?string,position:?string,...}> $scoringLineup
     *  @param list<array{id:?string,name:?string,position:?string,...}> $opposingLineup
     *  @param array<string, GameEventTemplate[]> $rowsByNodeType
     *  @param array<string, list<string>> $graph
     *  @return list<array{minute:int,isKeyEvent:bool,eventType:?string,text:string,playerA:?string,playerB:?string,side:?string}> */
    private function buildGoalChainItems(SeededRng $rng, array $event, CompetitionEntrant $home, CompetitionEntrant $away, array $homeLineup, array $awayLineup, string $homeName, string $awayName, array $rowsByNodeType, array $graph): array
    {
        $isHome         = $event['team'] === 'home';
        $scoringLineup  = $isHome ? $homeLineup : $awayLineup;
        $opposingLineup = $isHome ? $awayLineup : $homeLineup;
        $scoringName    = $isHome ? $homeName : $awayName;
        $opposingName   = $isHome ? $awayName : $homeName;
        $side           = $isHome ? 'HOME' : 'AWAY';

        $availableEntries = array_intersect_key(self::GOAL_CHAIN_ENTRY_WEIGHTS, $rowsByNodeType);
        if ($availableEntries === []) {
            return [];
        }
        $entry = $rng->weightedPick(array_keys($availableEntries), array_values($availableEntries));
        $chain = $this->walkScoringChain($rng, $entry, $graph, self::GOAL_CHAIN_MAX_STEPS);

        $attacker  = $this->playerName($scoringLineup, $event['scorer'] ?? null) ?? $this->randomPlayerName($rng, $scoringLineup, 'ATT');
        $assistBy  = $this->playerName($scoringLineup, $event['assist'] ?? null);
        $goalkeeper = $this->randomPlayerName($rng, $opposingLineup, 'GK');
        $defender   = $this->randomPlayerName($rng, $opposingLineup, 'DEF');
        $taker      = $attacker;

        $entities = array_filter([
            'attacker'   => $attacker,
            'goalkeeper' => $goalkeeper,
            'defender'   => $defender,
            'taker'      => $taker,
        ], static fn ($v) => $v !== null);

        $items    = [];
        $keyIndex = count($chain) - 1;
        foreach ($chain as $i => $nodeType) {
            $template = $this->pickTemplateText($rng, $nodeType, $rowsByNodeType);
            if ($template === null) {
                continue;
            }

            $isKey = $i === $keyIndex && $this->isScoringType($nodeType);
            $text  = $this->renderTemplate($template, $entities, $isKey ? $scoringName : ($this->fillerSideIsHome($nodeType) ? $scoringName : $opposingName));
            if ($isKey && $assistBy !== null) {
                $text .= " (assist: {$assistBy})";
            }

            $items[] = [
                'minute'     => max(1, (int) $event['minute'] - ($keyIndex - $i)),
                'isKeyEvent' => $isKey,
                'eventType'  => $isKey ? 'GOAL' : 'FILLER',
                'text'       => $text,
                'playerA'    => $attacker,
                'playerB'    => $assistBy,
                'side'       => $side,
            ];
        }

        return $items;
    }

    /** Corner/save/blocked filler nodes belong to the defending side narratively — used only for the fallback-name choice. */
    private function fillerSideIsHome(string $nodeType): bool
    {
        return !in_array($nodeType, ['GOAL_ATTEMPT_SAVED', 'GOAL_ATTEMPT_BLOCKED', 'CORNER_ATTEMPT_CLEARED'], true);
    }

    /** @return list<array{minute:int,isKeyEvent:bool,eventType:?string,text:string,playerA:?string,playerB:?string,side:?string}> */
    private function buildCardChainItems(SeededRng $rng, array $event, CompetitionEntrant $home, CompetitionEntrant $away, array $homeLineup, array $awayLineup, string $homeName, string $awayName, array $rowsByNodeType, array $graph): array
    {
        $isHome      = $event['team'] === 'home';
        $lineup      = $isHome ? $homeLineup : $awayLineup;
        $opponent    = $isHome ? $awayLineup : $homeLineup;
        $side        = $isHome ? 'HOME' : 'AWAY';
        $playerName  = $this->playerName($lineup, $event['player'] ?? null) ?? $this->randomPlayerName($rng, $lineup);
        $opponentName = $this->randomPlayerName($rng, $opponent);

        $items = [];

        $foulTemplate = $this->pickTemplateText($rng, 'DISCIPLINE_FOUL', $rowsByNodeType);
        if ($foulTemplate !== null) {
            $items[] = [
                'minute'     => max(1, (int) $event['minute'] - 1),
                'isKeyEvent' => false,
                'eventType'  => 'FILLER',
                'text'       => $this->renderTemplate($foulTemplate, array_filter(['playerA' => $playerName, 'playerB' => $opponentName]), $isHome ? $homeName : $awayName),
                'playerA'    => $playerName,
                'playerB'    => $opponentName,
                'side'       => $side,
            ];
        }

        $cardNodeType = $event['type'] === 'red_card' ? 'CARD_RED' : 'CARD_YELLOW';
        $cardTemplate = $this->pickTemplateText($rng, $cardNodeType, $rowsByNodeType);
        if ($cardTemplate !== null) {
            $items[] = [
                'minute'     => (int) $event['minute'],
                'isKeyEvent' => true,
                'eventType'  => $event['type'] === 'red_card' ? 'RED_CARD' : 'YELLOW_CARD',
                'text'       => $this->renderTemplate($cardTemplate, array_filter(['player' => $playerName]), $isHome ? $homeName : $awayName),
                'playerA'    => $playerName,
                'playerB'    => null,
                'side'       => $side,
            ];
        }

        return $items;
    }

    /**
     * @param array<string, GameEventTemplate[]> $rowsByNodeType
     * @param array<string, list<string>> $graph
     * @param list<array{minute:int,type:string}> $eventLog
     * @return list<array{minute:int,isKeyEvent:bool,eventType:?string,text:string,playerA:?string,playerB:?string,side:?string}>
     */
    private function buildFillerItems(SeededRng $rng, CompetitionEntrant $home, CompetitionEntrant $away, array $homeLineup, array $awayLineup, string $homeName, string $awayName, array $rowsByNodeType, array $graph, array $eventLog, int $maxMinute = 90): array
    {
        $availableEntries = array_intersect_key(self::FILLER_ENTRY_WEIGHTS, $rowsByNodeType);
        if ($availableEntries === []) {
            return [];
        }

        $occupiedMinutes = array_map(static fn (array $e) => (int) $e['minute'], $eventLog);
        $items           = [];

        for ($minute = self::FILLER_INTERVAL_MINUTES; $minute < $maxMinute; $minute += self::FILLER_INTERVAL_MINUTES) {
            $tooClose = false;
            foreach ($occupiedMinutes as $occupied) {
                if (abs($occupied - $minute) <= 3) {
                    $tooClose = true;
                    break;
                }
            }
            if ($tooClose) {
                continue;
            }

            $isHome      = $rng->chance(0.5);
            $lineup      = $isHome ? $homeLineup : $awayLineup;
            $teamName    = $isHome ? $homeName : $awayName;
            $side        = $isHome ? 'HOME' : 'AWAY';

            $entry = $rng->weightedPick(array_keys($availableEntries), array_values($availableEntries));
            $chain = $this->walkFillerChain($rng, $entry, $graph, self::FILLER_MAX_STEPS);

            $playerA = $this->randomPlayerName($rng, $lineup);
            $playerB = $this->randomPlayerName($rng, $lineup, null, null);

            foreach ($chain as $i => $nodeType) {
                $template = $this->pickTemplateText($rng, $nodeType, $rowsByNodeType);
                if ($template === null) {
                    continue;
                }

                $items[] = [
                    'minute'     => max(1, min($maxMinute - 1, $minute + $i)),
                    'isKeyEvent' => false,
                    'eventType'  => 'FILLER',
                    'text'       => $this->renderTemplate($template, array_filter(['playerA' => $playerA, 'playerB' => $playerB, 'attacker' => $playerA, 'taker' => $playerA]), $teamName),
                    'playerA'    => $playerA,
                    'playerB'    => $playerB,
                    'side'       => $side,
                ];
            }
        }

        return $items;
    }
}
