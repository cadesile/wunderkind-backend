<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\PlayerArchetypeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: PlayerArchetypeRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/archetypes',
            normalizationContext: ['groups' => ['archetype:read']],
            security: "is_granted('ROLE_ACADEMY')",
        ),
    ]
)]
class PlayerArchetype
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['archetype:read'])]
    private ?int $id = null;

    /**
     * Display name shown to the user in-game, e.g. "The Captain".
     */
    #[ORM\Column(length: 100, unique: true)]
    #[Groups(['archetype:read'])]
    private string $name;

    /**
     * Flavour text explaining the archetype's personality.
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['archetype:read'])]
    private string $description;

    /**
     * Rule set evaluated by the client to assign this archetype.
     *
     * Schema:
     * {
     *   "threshold": "all" | "any",
     *   "rules": [
     *     {"trait": "leadership", "min": 70},
     *     {"trait": "teamwork",   "min": 65},
     *     {"trait": "ego",        "max": 40}
     *   ]
     * }
     *
     * Traits: confidence, maturity, teamwork, leadership, ego, bravery, greed, loyalty
     * threshold "all" = every rule must match; "any" = at least one rule must match
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    #[Groups(['archetype:read'])]
    private array $traitMapping = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $name = '',
        string $description = '',
        array $traitMapping = [],
    ) {
        $this->name         = $name;
        $this->description  = $description;
        $this->traitMapping = $traitMapping;
        $this->createdAt    = new \DateTimeImmutable();
        $this->updatedAt    = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): void { $this->description = $description; }

    public function getTraitMapping(): array { return $this->traitMapping; }
    public function setTraitMapping(array $traitMapping): void { $this->traitMapping = $traitMapping; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
