<?php

namespace App\Entity;

use App\Repository\FacilityTemplateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: FacilityTemplateRepository::class)]
class FacilityTemplate
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /** Canonical slug shared with the frontend, e.g. 'technical_zone' */
    #[ORM\Column(length: 60, unique: true)]
    private string $slug;

    #[ORM\Column(length: 100)]
    private string $label;

    #[ORM\Column(type: 'text')]
    private string $description;

    /** TRAINING | MEDICAL | SCOUTING */
    #[ORM\Column(length: 20)]
    private string $category;

    /** Base cost per upgrade level in pence. Frontend formula: (currentLevel + 1) × baseCost */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $baseCost;

    /** Base weekly upkeep cost in pence at level 1. Frontend formula: baseCost × 1.5^level */
    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $weeklyUpkeepBase = 0;

    /** Reputation awarded to the academy per upgrade level */
    #[ORM\Column(type: 'float', options: ['default' => 0.0])]
    private float $reputationBonus = 0.0;

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 5])]
    private int $maxLevel = 5;

    /** Weekly condition decay base. Frontend formula: decayBase + level */
    #[ORM\Column(type: 'float', options: ['default' => 2.0])]
    private float $decayBase = 2.0;

    /** Controls display order in the facilities screen */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $slug = '',
        string $label = '',
        string $description = '',
        string $category = 'TRAINING',
        int $baseCost = 0,
    ) {
        $this->id          = new UuidV7();
        $this->slug        = $slug;
        $this->label       = $label;
        $this->description = $description;
        $this->category    = $category;
        $this->baseCost    = $baseCost;
        $this->updatedAt   = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = $slug; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): void { $this->label = $label; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): void { $this->description = $description; }

    public function getCategory(): string { return $this->category; }
    public function setCategory(string $category): void { $this->category = $category; }

    public function getBaseCost(): int { return $this->baseCost; }
    public function setBaseCost(int $baseCost): void { $this->baseCost = max(0, $baseCost); }

    public function getWeeklyUpkeepBase(): int { return $this->weeklyUpkeepBase; }
    public function setWeeklyUpkeepBase(int $weeklyUpkeepBase): void { $this->weeklyUpkeepBase = max(0, $weeklyUpkeepBase); }

    public function getReputationBonus(): float { return $this->reputationBonus; }
    public function setReputationBonus(float $reputationBonus): void { $this->reputationBonus = $reputationBonus; }

    public function getMaxLevel(): int { return $this->maxLevel; }
    public function setMaxLevel(int $maxLevel): void { $this->maxLevel = max(1, $maxLevel); }

    public function getDecayBase(): float { return $this->decayBase; }
    public function setDecayBase(float $decayBase): void { $this->decayBase = max(0.0, $decayBase); }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): void { $this->sortOrder = $sortOrder; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): void { $this->isActive = $isActive; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void { $this->updatedAt = $updatedAt; }

    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function toArray(): array
    {
        return [
            'slug'             => $this->slug,
            'label'            => $this->label,
            'description'      => $this->description,
            'category'         => $this->category,
            'baseCost'         => $this->baseCost,
            'weeklyUpkeepBase' => $this->weeklyUpkeepBase,
            'reputationBonus'  => $this->reputationBonus,
            'maxLevel'         => $this->maxLevel,
            'decayBase'        => $this->decayBase,
            'sortOrder'        => $this->sortOrder,
        ];
    }
}
