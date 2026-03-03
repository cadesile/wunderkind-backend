<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class SyncRequest
{
    #[Assert\Positive]
    public int $weekNumber;

    #[Assert\NotBlank]
    public string $clientTimestamp;

    #[Assert\PositiveOrZero]
    public float $earningsDelta;

    public float $reputationDelta = 0;

    #[Assert\PositiveOrZero]
    public float $hallOfFamePoints = 0;

    public array $transfers = [];
}
