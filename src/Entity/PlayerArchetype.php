<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ArchetypePolarity;
use App\Repository\PlayerArchetypeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlayerArchetypeRepository::class)]
#[ORM\HasLifecycleCallbacks]
class PlayerArchetype
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Stable machine identity, shared with the client. Match on this, never on name. */
    #[ORM\Column(length: 100, unique: true)]
    private string $slug;

    #[ORM\Column(length: 100, unique: true)]
    private string $name;

    /** Scouting-report flavour text describing the archetype's personality. */
    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(length: 16, enumType: ArchetypePolarity::class)]
    private ArchetypePolarity $polarity;

    /**
     * Weighted formula evaluated by the client to assign this archetype.
     *
     * Schema:
     * {
     *   "formula":   {"professionalism": 0.5, "determination": 0.5},
     *   "threshold": 65
     * }
     *
     * Valid trait keys are EXACTLY the eight fields of {@see PersonalityProfile}:
     *   determination, professionalism, ambition, loyalty,
     *   adaptability, pressure, temperament, consistency
     *
     * `bravery`, `ego` and `confidence` are NOT personality traits. They appeared in the
     * pre-2026-08 catalogue, scored zero for every player, and were the reason archetypes
     * resolved to null client-side. Never reintroduce them.
     *
     * Weights are SIGNED. A positive weight scores the trait directly ("High X"); a negative
     * weight scores its inverse ("Low X"). The absolute values must sum to 1.0.
     *
     * Traits are persisted on a 1-20 scale. The client normalises each to 0-100 as
     * `(value / 20) * 100` before applying weights, so:
     *
     *   score = SUM( w > 0 ?  w  * norm(trait)
     *                       : |w| * (100 - norm(trait)) )
     *
     * The player matches this archetype when `score >= threshold`.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $traitWeights = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $slug = '',
        string $name = '',
        string $description = '',
        ArchetypePolarity $polarity = ArchetypePolarity::POSITIVE,
        array $traitWeights = [],
    ) {
        $this->slug         = $slug;
        $this->name         = $name;
        $this->description  = $description;
        $this->polarity     = $polarity;
        $this->traitWeights = $traitWeights;
        $this->createdAt    = new \DateTimeImmutable();
        $this->updatedAt    = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = $slug; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): void { $this->description = $description; }

    public function getPolarity(): ArchetypePolarity { return $this->polarity; }
    public function setPolarity(ArchetypePolarity $polarity): void { $this->polarity = $polarity; }

    public function getTraitWeights(): array { return $this->traitWeights; }
    public function setTraitWeights(array $traitWeights): void { $this->traitWeights = $traitWeights; }

    /** Virtual property for admin form — serialises traitWeights as a JSON string. */
    public function getTraitWeightsJson(): string
    {
        return json_encode($this->traitWeights, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function setTraitWeightsJson(string $json): void
    {
        $decoded = json_decode($json, true);
        $this->traitWeights = is_array($decoded) ? $decoded : [];
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
