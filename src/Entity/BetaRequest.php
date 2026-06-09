<?php

namespace App\Entity;

use App\Repository\BetaRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: BetaRequestRepository::class)]
#[ORM\Table(name: 'beta_request')]
#[ORM\Index(columns: ['email'], name: 'idx_beta_request_email')]
class BetaRequest
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(type: 'string', length: 180)]
    private string $email;

    #[ORM\Column(type: 'string', length: 6)]
    private string $code;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $valid = false;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    public function __construct(string $email, string $code)
    {
        $this->id        = new UuidV7();
        $this->email     = $email;
        $this->code      = $code;
        $this->expiresAt = new \DateTimeImmutable('+15 minutes');
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function getCode(): string { return $this->code; }
    public function isValid(): bool { return $this->valid; }
    public function getAttempts(): int { return $this->attempts; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }

    public function markVerified(): void
    {
        $this->valid      = true;
        $this->verifiedAt = new \DateTimeImmutable();
    }

    public function incrementAttempts(): void { $this->attempts++; }
    public function isExpired(): bool { return $this->expiresAt <= new \DateTimeImmutable(); }
    public function isLockedOut(): bool { return $this->attempts >= 3; }
    public function expire(): void { $this->expiresAt = new \DateTimeImmutable(); }
}
