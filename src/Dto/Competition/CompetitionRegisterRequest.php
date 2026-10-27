<?php

namespace App\Dto\Competition;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload for POST /api/competitions/{id}/register. Envelope-level validation only —
 * SnapshotValidator does the deep structural pass in the controller, including the total
 * size bound.
 *
 * `facilities` is gone. It was accepted here and persisted on every entrant, but nothing in
 * the codebase ever read it back, and it was decoded on every bracket render. A client that
 * still sends the key is unaffected: MapRequestPayload ignores properties the DTO does not
 * declare, so this is not a breaking change for callers.
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

    public ?string $clientSnapshotAt = null;
}
