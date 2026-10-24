<?php

namespace App\Service\MatchEngine;

/**
 * Plain value object — the caller (CompetitionResultsService) is responsible for
 * persisting this into a CompetitionResult.
 */
final class MatchEngineResult
{
    /**
     * @param list<array{minute: int, type: string, team?: string, scorer?: ?string, assist?: ?string, player?: ?string}> $eventLog
     * @param list<array{id: ?string, name: ?string, position: ?string, appearance?: ?array<string, mixed>, goals: int, assists: int, yellowCards: int, redCards: int, rating: float}> $homeLineup
     * @param list<array{id: ?string, name: ?string, position: ?string, appearance?: ?array<string, mixed>, goals: int, assists: int, yellowCards: int, redCards: int, rating: float}> $awayLineup
     * @param array<string, mixed>|null $computationAudit Engine-agnostic audit trail detailing inputs, intermediate weights/multipliers, RNG seed/rolls, and how the final result was derived.
     */
    public function __construct(
        public readonly int $homeScore,
        public readonly int $awayScore,
        public readonly array $eventLog,
        public readonly ?array $narrativePayload = null,
        public readonly array $homeLineup = [],
        public readonly array $awayLineup = [],
        public readonly bool $wentToExtraTime = false,
        public readonly bool $wentToPenalties = false,
        public readonly ?int $penaltyHomeScore = null,
        public readonly ?int $penaltyAwayScore = null,
        public readonly ?array $computationAudit = null,
    ) {}
}
