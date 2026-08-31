<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ConcludeSeasonRequest
{
    /** The club this season belongs to. See SyncRequest::$clubId — same semantics. */
    #[Assert\Uuid]
    public ?string $clubId = null;

    #[Assert\Positive]
    public int $finalPosition = 1;

    #[Assert\PositiveOrZero]
    public int $gamesPlayed = 0;

    #[Assert\PositiveOrZero]
    public int $wins = 0;

    #[Assert\PositiveOrZero]
    public int $draws = 0;

    #[Assert\PositiveOrZero]
    public int $losses = 0;

    #[Assert\PositiveOrZero]
    public int $goalsFor = 0;

    #[Assert\PositiveOrZero]
    public int $goalsAgainst = 0;

    #[Assert\PositiveOrZero]
    public int $points = 0;

    public bool $promoted = false;

    public bool $relegated = false;

    /** @var array<string, mixed> */
    public array $pyramidSnapshot = [];
}
