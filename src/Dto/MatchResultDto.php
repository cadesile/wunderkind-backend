<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class MatchResultDto
{
    #[Assert\Uuid]
    public string $opponentClubId = '';

    #[Assert\PositiveOrZero]
    public int $goalsFor = 0;

    #[Assert\PositiveOrZero]
    public int $goalsAgainst = 0;

    #[Assert\Positive]
    public int $week = 1;
}
