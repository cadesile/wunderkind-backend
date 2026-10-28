<?php

namespace App\Dto\Competition;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload for POST /api/competitions/{id}/register. Envelope-level validation only —
 * SnapshotValidator does the deep structural pass in the controller, including the total
 * size bound.
 *
 * `facilities` is accepted again, but it does NOT go back onto the entrant. It was removed
 * because it was persisted on every entrant and decoded on every bracket render while
 * nothing ever read it; it is declared here solely so it reaches the verbatim submission
 * row, which no hot path loads. Declaring it is required, not cosmetic — MapRequestPayload
 * drops undeclared properties, so an undeclared key never reaches the service at all and
 * could not be stored even verbatim.
 */
class CompetitionRegisterRequest
{
    #[Assert\NotBlank]
    #[Assert\Type('array')]
    public array $club = [];

    #[Assert\NotBlank]
    #[Assert\Type('array')]
    public array $players = [];

    #[Assert\Type('array')]
    public array $staff = [];

    /**
     * Every facility the client knows about, including level-0 ones — a `slug => int` map.
     * Stored verbatim and read by nothing; see the class docblock.
     *
     * @var array<string, mixed>
     */
    #[Assert\Type('array')]
    public array $facilities = [];

    public ?string $clientSnapshotAt = null;
}
