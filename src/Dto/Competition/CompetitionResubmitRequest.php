<?php

namespace App\Dto\Competition;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload for POST /api/competitions/{id}/resubmit. clubId is body-carried (not
 * header-resolved) — a resubmission can be built on-device and sent later, after the
 * "current" save may have changed, same offline-queue-parity rationale as SyncRequest.
 */
class CompetitionResubmitRequest
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $clubId;

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
