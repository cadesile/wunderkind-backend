<?php

namespace App\Service\Competition;

/**
 * Pure capacity -> round-label mapping for a single-elimination knockout bracket.
 * Labels are what CompetitionTemplate::roundEngineConfig is keyed by (stable across
 * templates of different capacities), and what each CompetitionRound::label stores.
 */
final class BracketLabeler
{
    /**
     * @return list<string> Labels in play order, index 0 = round 1.
     */
    public static function labelsForCapacity(int $entrantCapacity): array
    {
        return match ($entrantCapacity) {
            4  => ['SF', 'FINAL'],
            8  => ['QF', 'SF', 'FINAL'],
            16 => ['R16', 'QF', 'SF', 'FINAL'],
            32 => ['R32', 'R16', 'QF', 'SF', 'FINAL'],
            64 => ['R64', 'R32', 'R16', 'QF', 'SF', 'FINAL'],
            default => throw new \InvalidArgumentException("Unsupported entrant capacity: $entrantCapacity"),
        };
    }
}
