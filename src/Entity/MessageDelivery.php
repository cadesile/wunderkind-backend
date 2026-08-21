<?php

namespace App\Entity;

use App\Enum\MessageDeliveryStatus;
use App\Repository\MessageDeliveryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Tracks whether one AdminMessage has been shown to one User.
 *
 * Keyed to User rather than Club, even though targeting is evaluated against a Club: a person
 * should see an announcement once. ClubRepository::findByUser() resolves only the user's most
 * recently created club, so a club-keyed row would let a player start a new club and have
 * every active announcement replay. Guests and registered accounts are both plain User rows
 * and are treated identically here — nothing in this system inspects verification status or
 * the synthetic guest email domain.
 *
 * The (user, message) unique constraint IS the idempotency guarantee for
 * POST /api/messages/{id}/ack — AdminMessageService relies on it to collapse concurrent acks
 * from a retrying client into a single row, so do not drop it as a mere hygiene index.
 */
#[ORM\Entity(repositoryClass: MessageDeliveryRepository::class)]
#[ORM\Table(name: 'message_delivery')]
#[ORM\UniqueConstraint(name: 'uq_message_delivery', columns: ['user_id', 'message_id'])]
class MessageDelivery
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'message_id', nullable: false, onDelete: 'CASCADE')]
    private AdminMessage $message;

    #[ORM\Column]
    private \DateTimeImmutable $deliveredAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $displayedAt = null;

    #[ORM\Column(type: 'string', enumType: MessageDeliveryStatus::class, options: ['default' => 'pending'])]
    private MessageDeliveryStatus $status = MessageDeliveryStatus::PENDING;

    public function __construct(User $user, AdminMessage $message)
    {
        $this->id          = new UuidV7();
        $this->user        = $user;
        $this->message     = $message;
        $this->deliveredAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getMessage(): AdminMessage
    {
        return $this->message;
    }

    public function getDeliveredAt(): \DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function getDisplayedAt(): ?\DateTimeImmutable
    {
        return $this->displayedAt;
    }

    public function getStatus(): MessageDeliveryStatus
    {
        return $this->status;
    }

    /**
     * Applies an acknowledgement.
     *
     * DISMISSED is terminal: a late-arriving "displayed" ack (the client acks on render and
     * again on dismiss, and the two can race or arrive out of order) must not walk the row
     * back to a weaker state.
     */
    public function acknowledge(MessageDeliveryStatus $status): self
    {
        if ($this->status === MessageDeliveryStatus::DISMISSED) {
            return $this;
        }

        $this->status = $status;

        if ($status !== MessageDeliveryStatus::PENDING && $this->displayedAt === null) {
            $this->displayedAt = new \DateTimeImmutable();
        }

        return $this;
    }
}
