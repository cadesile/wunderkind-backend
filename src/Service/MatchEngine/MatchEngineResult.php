<?php

namespace App\Service\MatchEngine;

/**
 * Plain value object — the caller (CompetitionRoundProcessorService) is responsible for
 * persisting this into a CompetitionResult.
 */
final class MatchEngineResult
{
    /** @param list<array{minute: int, type: string, team?: string, scorer?: ?string}> $eventLog */
    public function __construct(
        public readonly int $homeScore,
        public readonly int $awayScore,
        public readonly array $eventLog,
        public readonly ?array $narrativePayload = null,
    ) {}
}
