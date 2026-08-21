<?php

namespace App\Entity;

use App\Enum\MessageDisplayType;
use App\Enum\MessagePriority;
use App\Enum\MessageTargetType;
use App\Repository\AdminMessageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * An operator-authored announcement: release notes, incident notices, targeted campaigns.
 *
 * Distinct from InboxMessage, which is in-game fiction written by game services for a single
 * club. This is written by a human in EasyAdmin and fanned out to a cohort.
 *
 * bodyHtml is sanitised on write by AdminMessageService::sanitize() (called from
 * AdminMessageCrudController), so the column only ever holds allowlisted markup.
 */
#[ORM\Entity(repositoryClass: AdminMessageRepository::class)]
#[ORM\Table(name: 'admin_message')]
#[ORM\Index(columns: ['is_active', 'valid_from'], name: 'idx_admin_message_active_window')]
class AdminMessage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 150)]
    private string $title = '';

    #[ORM\Column(type: 'text')]
    private string $bodyHtml = '';

    #[ORM\Column(type: 'string', enumType: MessageTargetType::class, options: ['default' => 'broadcast'])]
    private MessageTargetType $targetType = MessageTargetType::BROADCAST;

    #[ORM\Column(type: 'smallint', enumType: MessagePriority::class, options: ['default' => 2])]
    private MessagePriority $priority = MessagePriority::STANDARD;

    #[ORM\Column(type: 'string', enumType: MessageDisplayType::class, options: ['default' => 'inbox_item'])]
    private MessageDisplayType $displayType = MessageDisplayType::INBOX_ITEM;

    #[ORM\Column]
    private \DateTimeImmutable $validFrom;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    /**
     * SET NULL rather than CASCADE — removing an admin account must not delete the messages
     * they published.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Admin $createdBy = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isActive = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Targeting for MessageTargetType::GROUP_SEGMENTED. A message matches if it matches ANY
     * of its groups.
     *
     * @var Collection<int, AudienceGroup>
     */
    #[ORM\ManyToMany(targetEntity: AudienceGroup::class)]
    #[ORM\JoinTable(name: 'admin_message_audience_group')]
    private Collection $audienceGroups;

    /** Targeting for MessageTargetType::DIRECT_CLUB. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Club $targetClub = null;

    public function __construct()
    {
        $this->id             = new UuidV7();
        $this->createdAt      = new \DateTimeImmutable();
        $this->validFrom      = new \DateTimeImmutable();
        $this->audienceGroups = new ArrayCollection();
    }

    public function getId(): UuidV7
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getBodyHtml(): string
    {
        return $this->bodyHtml;
    }

    public function setBodyHtml(string $bodyHtml): self
    {
        $this->bodyHtml = $bodyHtml;

        return $this;
    }

    public function getTargetType(): MessageTargetType
    {
        return $this->targetType;
    }

    public function setTargetType(MessageTargetType $targetType): self
    {
        $this->targetType = $targetType;

        return $this;
    }

    public function getPriority(): MessagePriority
    {
        return $this->priority;
    }

    public function setPriority(MessagePriority $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function getDisplayType(): MessageDisplayType
    {
        return $this->displayType;
    }

    public function setDisplayType(MessageDisplayType $displayType): self
    {
        $this->displayType = $displayType;

        return $this;
    }

    public function getValidFrom(): \DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(\DateTimeImmutable $validFrom): self
    {
        $this->validFrom = $validFrom;

        return $this;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function setValidUntil(?\DateTimeImmutable $validUntil): self
    {
        $this->validUntil = $validUntil;

        return $this;
    }

    public function getCreatedBy(): ?Admin
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?Admin $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, AudienceGroup> */
    public function getAudienceGroups(): Collection
    {
        return $this->audienceGroups;
    }

    public function addAudienceGroup(AudienceGroup $group): self
    {
        if (!$this->audienceGroups->contains($group)) {
            $this->audienceGroups->add($group);
        }

        return $this;
    }

    public function removeAudienceGroup(AudienceGroup $group): self
    {
        $this->audienceGroups->removeElement($group);

        return $this;
    }

    public function getTargetClub(): ?Club
    {
        return $this->targetClub;
    }

    public function setTargetClub(?Club $targetClub): self
    {
        $this->targetClub = $targetClub;

        return $this;
    }

    public function __toString(): string
    {
        return $this->title;
    }
}
