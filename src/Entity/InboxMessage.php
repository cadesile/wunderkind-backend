<?php

namespace App\Entity;

use App\Enum\MessageSenderType;
use App\Enum\MessageStatus;
use App\Repository\InboxMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: InboxMessageRepository::class)]
#[ORM\Index(columns: ['academy_id'], name: 'IDX_inbox_academy')]
class InboxMessage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(inversedBy: 'inboxMessages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Academy $academy;

    #[ORM\Column(type: 'string', enumType: MessageSenderType::class)]
    private MessageSenderType $senderType;

    #[ORM\Column(length: 150)]
    private string $senderName;

    #[ORM\Column(length: 255)]
    private string $subject;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $offerData = null;

    #[ORM\Column(type: 'string', enumType: MessageStatus::class, options: ['default' => 'unread'])]
    private MessageStatus $status = MessageStatus::UNREAD;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $relatedEntityType = null;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $relatedEntityId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    public function __construct(
        Academy $academy,
        MessageSenderType $senderType,
        string $senderName,
        string $subject,
        string $body,
    ) {
        $this->id         = new UuidV7();
        $this->academy    = $academy;
        $this->senderType = $senderType;
        $this->senderName = $senderName;
        $this->subject    = $subject;
        $this->body       = $body;
        $this->createdAt  = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getAcademy(): Academy { return $this->academy; }

    public function getSenderType(): MessageSenderType { return $this->senderType; }

    public function getSenderName(): string { return $this->senderName; }

    public function getSubject(): string { return $this->subject; }

    public function getBody(): string { return $this->body; }

    public function getOfferData(): ?array { return $this->offerData; }
    public function setOfferData(?array $offerData): void { $this->offerData = $offerData; }

    public function getStatus(): MessageStatus { return $this->status; }

    public function getRelatedEntityType(): ?string { return $this->relatedEntityType; }
    public function setRelatedEntityType(?string $type): void { $this->relatedEntityType = $type; }

    public function getRelatedEntityId(): ?string { return $this->relatedEntityId; }
    public function setRelatedEntityId(?string $id): void { $this->relatedEntityId = $id; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getRespondedAt(): ?\DateTimeImmutable { return $this->respondedAt; }

    public function markAsRead(): void
    {
        if ($this->status === MessageStatus::UNREAD) {
            $this->status = MessageStatus::READ;
        }
    }

    public function accept(): void
    {
        $this->status      = MessageStatus::ACCEPTED;
        $this->respondedAt = new \DateTimeImmutable();
    }

    public function reject(): void
    {
        $this->status      = MessageStatus::REJECTED;
        $this->respondedAt = new \DateTimeImmutable();
    }
}
