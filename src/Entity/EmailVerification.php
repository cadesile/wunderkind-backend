<?php

namespace App\Entity;

use App\Repository\EmailVerificationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: EmailVerificationRepository::class)]
#[ORM\Table(name: 'email_verification')]
class EmailVerification
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** 6-digit numeric code e.g. "847291" */
    #[ORM\Column(type: 'string', length: 6)]
    private string $code;

    /** Expires 15 minutes after creation */
    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $expiresAt;

    /** Number of failed attempts. Lockout after 3. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $code)
    {
        $this->id        = new UuidV7();
        $this->user      = $user;
        $this->code      = $code;
        $this->expiresAt = new \DateTimeImmutable('+15 minutes');
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getCode(): string { return $this->code; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): void { $this->expiresAt = $expiresAt; }
    public function getAttempts(): int { return $this->attempts; }
    public function getVerifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function isVerified(): bool
    {
        return $this->verifiedAt !== null;
    }

    public function isLockedOut(): bool
    {
        return $this->attempts >= 3;
    }

    public function incrementAttempts(): void
    {
        $this->attempts++;
    }

    public function markVerified(): void
    {
        $this->verifiedAt = new \DateTimeImmutable();
    }
}
