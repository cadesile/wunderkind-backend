<?php

declare(strict_types=1);

namespace App\Entity;

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

    #[ORM\Column(length: 100, unique: true)]
    private string $name;

    /** Scouting-report flavour text describing the archetype's personality. */
    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    /**
     * Weighted formula evaluated by the client to assign this archetype.
     *
     * Schema:
     * {
     *   "formula":   {"bravery": 0.4, "consistency": 0.3, "loyalty": 0.3},
     *   "threshold": 70
     * }
     *
     * Available traits: bravery, consistency, loyalty, professionalism,
     *                   ambition, ego, confidence, pressure
     *
     * Weights must sum to 1.0. Threshold is the minimum weighted score (0–100)
     * for the player to match this archetype.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
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
