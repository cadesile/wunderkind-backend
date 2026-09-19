<?php

namespace App\Service\MatchEngine;

/**
 * Plain value object — the caller (CompetitionRoundProcessorService) is responsible for
 * persisting this into a CompetitionResult.
 */
final class MatchEngineResult
{
    /**
     * @param list<array{minute: int, type: string, team?: string, scorer?: ?string, assist?: ?string, player?: ?string}> $eventLog
     * @param list<array{id: ?string, name: ?string, position: ?string, goals: int, assists: int, yellowCards: int, redCards: int, rating: float}> $homeLineup
     * @param list<array{id: ?string, name: ?string, position: ?string, goals: int, assists: int, yellowCards: int, redCards: int, rating: float}> $awayLineup
     */
    public function __construct(
        public readonly int $homeScore,
        public readonly int $awayScore,
        public readonly array $eventLog,
        public readonly ?array $narrativePayload = null,
        public readonly array $homeLineup = [],
        public readonly array $awayLineup = [],
    ) {}
}
