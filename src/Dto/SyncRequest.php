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

    /**
     * Manager personality shift deltas sent by the client each week.
     * Keys: 'temperament', 'discipline', 'ambition'. Values: signed int deltas.
     * Example: {"temperament": 2, "discipline": -1, "ambition": 0}
     *
     * @var array<string, int>
     */
    public array $managerShifts = [];

    /**
     * Player attribute snapshots sent by the client each week.
     * Each entry: {playerId, pace, technical, vision, power, stamina, heart, height, weight, morale}
     *
     * @var array<array{playerId: string, pace?: int, technical?: int, vision?: int, power?: int, stamina?: int, heart?: int, height?: int, weight?: int, morale?: int}>
     */
    public array $players = [];
}
