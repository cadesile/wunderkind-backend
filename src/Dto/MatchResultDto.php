<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class MatchResultDto
{
    /** Client-side fixture UUID — idempotency key. */
    public string $fixtureId = '';

    public string $leagueId = '';
    public int $season = 0;
    public int $round = 0;

    #[Assert\Uuid]
    public string $opponentClubId = '';

    public string $opponentClubName = '';

    /** Goals scored by the home team. */
    public int $homeGoals = 0;

    /** Goals scored by the away team. */
    public int $awayGoals = 0;

    /** True if the AMP club was the home side. */
    public bool $isHome = false;

    /** ISO 8601 timestamp when the match was played (client-side). */
    public string $playedAt = '';

    /** @var array<array{playerId: string, type: 'yellow'|'red', minute?: int}> */
    public array $cards = [];

    /** @var array<array{playerId: string, suspendedMatches: int}> */
    public array $suspensions = [];

    // ── Legacy v1 fields — kept for backward compatibility ─────────────

    #[Assert\PositiveOrZero]
    public int $goalsFor = 0;

    #[Assert\PositiveOrZero]
    public int $goalsAgainst = 0;

    #[Assert\Positive]
    public int $week = 1;
}
