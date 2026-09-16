<?php

namespace App\Dto\Competition;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload for POST /api/competitions/{id}/register. Envelope-level validation only —
 * SnapshotValidator does the deep structural pass in the controller.
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

    #[Assert\Type('array')]
    public array $facilities = [];

    public ?string $clientSnapshotAt = null;
}
