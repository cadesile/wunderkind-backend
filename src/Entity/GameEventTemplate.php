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

    /**
     * Optional firing conditions for NPC_INTERACTION templates.
     * Shape: { maxSquadMorale, minSquadMorale, maxPairRelationship, minPairRelationship,
     *           requiresCoLocation, actorTraitRequirements[], subjectTraitRequirements[] }
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $firingConditions = null;

    /** 'minor' = read-only inbox report. 'major' = AMP must respond. */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $severity = null;

    /**
     * Optional chain links — each entry boosts the weight of another event for the same
     * player pair within a configurable time window.
     * Shape: [{ nextEventSlug: string, boostMultiplier: float, windowWeeks: int, note: string|null }]
     *
     * @var array<int, array<string, mixed>>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $chainedEvents = null;

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

    /** Virtual property for admin form — serialises impacts as a JSON string. */
    public function getImpactsJson(): string
    {
        return json_encode($this->impacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    public function setImpactsJson(string $json): void
    {
        $decoded = json_decode($json, true);
        $this->impacts = is_array($decoded) ? $decoded : [];
    }

    public function getFiringConditions(): ?array { return $this->firingConditions; }
    public function setFiringConditions(?array $firingConditions): void { $this->firingConditions = $firingConditions; }

    /** Virtual property for admin form — serialises firingConditions as a JSON string. */
    public function getFiringConditionsJson(): string
    {
        return $this->firingConditions !== null
            ? (json_encode($this->firingConditions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}')
            : '';
    }

    public function setFiringConditionsJson(?string $json): void
    {
        $trimmed = trim($json ?? '');
        if ($trimmed === '') {
            $this->firingConditions = null;
            return;
        }
        $decoded = json_decode($trimmed, true);
        $this->firingConditions = is_array($decoded) ? $decoded : null;
    }

    public function getSeverity(): ?string { return $this->severity; }
    public function setSeverity(?string $severity): void { $this->severity = $severity; }

    public function getChainedEvents(): ?array { return $this->chainedEvents; }
    public function setChainedEvents(?array $chainedEvents): void { $this->chainedEvents = $chainedEvents; }

    /** Returns the chainedEvents array, defaulting to [] for form binding. */
    public function getChainedEventsArray(): array { return $this->chainedEvents ?? []; }
    public function setChainedEventsArray(array $links): void { $this->chainedEvents = empty($links) ? null : $links; }

    /** Virtual accessor for raw-JSON admin textarea (kept for import/export). */
    public function getChainedEventsJson(): string
    {
        return $this->chainedEvents !== null
            ? (json_encode($this->chainedEvents, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]')
            : '[]';
    }

    public function setChainedEventsJson(?string $json): void
    {
        $trimmed = trim($json ?? '');
        // Both empty string and '[]' mean "no chains configured"
        if ($trimmed === '' || $trimmed === '[]') {
            $this->chainedEvents = null;
            return;
        }
        $decoded = json_decode($trimmed, true);
        $this->chainedEvents = is_array($decoded) ? $decoded : null;
    }

    /**
     * Returns chainedEvents stripped of the admin-only 'note' field.
     * This is what the frontend API receives. Keys are present but may be null
     * if a stored link is missing expected fields.
     *
     * @return array<int, array{nextEventSlug: string|null, boostMultiplier: float|null, windowWeeks: int|null}>|null
     */
    public function getChainedEventsWithoutNotes(): ?array
    {
        if ($this->chainedEvents === null) return null;

        return array_map(static fn (array $link) => [
            'nextEventSlug'   => $link['nextEventSlug'] ?? null,
            'boostMultiplier' => $link['boostMultiplier'] ?? null,
            'windowWeeks'     => $link['windowWeeks'] ?? null,
        ], $this->chainedEvents);
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
