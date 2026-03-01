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
    public int $earningsDelta;

    public int $reputationDelta = 0;

    #[Assert\PositiveOrZero]
    public int $hallOfFamePoints = 0;

    public array $transfers = [];
}
