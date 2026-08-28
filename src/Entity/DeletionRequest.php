<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DeletionRequestStatus;
use App\Repository\DeletionRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Audit record of a web account-deletion request.
 *
 * Google Play and iOS both require a web-accessible deletion route, and both
 * expect you to be able to evidence that requests are actioned. This table is
 * that evidence: one row per attempt, successful or not.
 *
 * Note the deliberate tension — a COMPLETED row outlives the User it deleted and
 * still holds their email address. That is intentional (it is what lets you
 * answer "did you delete my account?"), but it means this table holds personal
 * data for accounts that no longer exist and therefore needs a retention policy;
 * `app:deletion-requests:purge` exists for that.
 */
#[ORM\Entity(repositoryClass: DeletionRequestRepository::class)]
#[ORM\Table(name: 'deletion_request')]
#[ORM\Index(columns: ['email'], name: 'idx_deletion_request_email')]
#[ORM\Index(columns: ['requested_at'], name: 'idx_deletion_request_requested_at')]
class DeletionRequest
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(type: 'string', length: 180)]
    private string $email;

    #[ORM\Column(type: 'string', length: 40, enumType: DeletionRequestStatus::class)]
    private DeletionRequestStatus $status;

    /** Length 45 fits an IPv6 address; null when the request arrived without one. */
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $failureReason = null;

    /** Clubs destroyed alongside the user. Recorded because it is unrecoverable. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $clubsDeleted = 0;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private \DateTimeImmutable|null $completedAt = null;

    public function __construct(string $email, DeletionRequestStatus $status, ?string $ipAddress = null)
    {
        $this->id          = new UuidV7();
        $this->email       = $email;
        $this->status      = $status;
        $this->ipAddress   = $ipAddress;
        $this->requestedAt = new \DateTimeImmutable();

        if ($status === DeletionRequestStatus::COMPLETED) {
            $this->completedAt = $this->requestedAt;
        }
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getEmail(): string { return $this->email; }

    public function getStatus(): DeletionRequestStatus { return $this->status; }

    public function getIpAddress(): ?string { return $this->ipAddress; }

    public function getFailureReason(): ?string { return $this->failureReason; }
    public function setFailureReason(?string $reason): static
    {
        // Truncate rather than let a long exception message break the insert.
        $this->failureReason = $reason === null ? null : mb_substr($reason, 0, 255);

        return $this;
    }

    public function getClubsDeleted(): int { return $this->clubsDeleted; }
    public function setClubsDeleted(int $count): static { $this->clubsDeleted = max(0, $count); return $this; }

    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }

    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
}
