<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Records each sync payload from the client.
 * Used for timestamp validation to prevent week-rollback exploits
 * against the global leaderboards.
 */
#[ORM\Entity]
class SyncRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(inversedBy: 'syncRecords')]
    #[ORM\JoinColumn(nullable: false)]
    private Academy $academy;

    /** Week number as reported by the client */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $clientWeekNumber;

    /** Client's local timestamp at the time of sync */
    #[ORM\Column]
    private \DateTimeImmutable $clientTimestamp;

    /** Server-side receipt time — used for anti-cheat delta checks */
    #[ORM\Column]
    private \DateTimeImmutable $serverTimestamp;

    /**
     * Summary payload from the weekly tick.
     * Stores aggregate figures (earnings delta, reputation delta, etc.)
     * rather than full game state.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $payload = [];

    /** False if validation fails (e.g. week number went backwards) */
    #[ORM\Column]
    private bool $isValid = true;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $invalidReason = null;

    public function __construct(
        Academy $academy,
        int $clientWeekNumber,
        \DateTimeImmutable $clientTimestamp,
        array $payload,
    ) {
        $this->id               = new UuidV7();
        $this->academy          = $academy;
        $this->clientWeekNumber = $clientWeekNumber;
        $this->clientTimestamp  = $clientTimestamp;
        $this->serverTimestamp  = new \DateTimeImmutable();
        $this->payload          = $payload;
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getAcademy(): Academy { return $this->academy; }

    public function getClientWeekNumber(): int { return $this->clientWeekNumber; }
    public function getClientTimestamp(): \DateTimeImmutable { return $this->clientTimestamp; }
    public function getServerTimestamp(): \DateTimeImmutable { return $this->serverTimestamp; }

    public function getPayload(): array { return $this->payload; }

    public function isValid(): bool { return $this->isValid; }

    public function markInvalid(string $reason): void
    {
        $this->isValid       = false;
        $this->invalidReason = $reason;
    }

    public function getInvalidReason(): ?string { return $this->invalidReason; }

    public function getPayloadJson(): string
    {
        return json_encode($this->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
