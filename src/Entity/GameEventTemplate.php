<?php

namespace App\Entity;

use App\Enum\EventCategory;
use App\Repository\GameEventTemplateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: GameEventTemplateRepository::class)]
class GameEventTemplate
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 100, unique: true)]
    private string $slug;

    #[ORM\Column(length: 30, enumType: EventCategory::class)]
    private EventCategory $category;

    /** Higher weight = more likely to be selected by the client */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 1])]
    private int $weight = 1;

    #[ORM\Column(length: 255)]
    private string $title;

    /** Template string with {player}, {staff}, {amount} placeholders */
    #[ORM\Column(type: 'text')]
    private string $bodyTemplate;

    /**
     * Array of impact descriptors consumed by the client engine.
     * Example: [{"target": "player.morale", "delta": -10}]
     *
     * @var array<int, array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $impacts = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $slug = '',
        EventCategory $category = EventCategory::PLAYER,
        string $title = '',
        string $bodyTemplate = '',
        array $impacts = [],
        int $weight = 1,
    ) {
        $this->id           = new UuidV7();
        $this->slug         = $slug;
        $this->category     = $category;
        $this->title        = $title;
        $this->bodyTemplate = $bodyTemplate;
        $this->impacts      = $impacts;
        $this->weight       = $weight;
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = $slug; }

    public function getCategory(): EventCategory { return $this->category; }
    public function setCategory(EventCategory|string $category): void
    {
        $this->category = is_string($category) ? EventCategory::from($category) : $category;
    }

    public function getWeight(): int { return $this->weight; }
    public function setWeight(int $weight): void { $this->weight = max(0, $weight); }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = $title; }

    public function getBodyTemplate(): string { return $this->bodyTemplate; }
    public function setBodyTemplate(string $bodyTemplate): void { $this->bodyTemplate = $bodyTemplate; }

    public function getImpacts(): array { return $this->impacts; }
    public function setImpacts(array $impacts): void { $this->impacts = $impacts; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
