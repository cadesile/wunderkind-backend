<?php

namespace App\Entity\Competition;

use App\Repository\Competition\RewardTemplateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Standalone reward definition — a list of GameEffect payloads (ledger_delta,
 * reputation_delta, unique_asset_grant). Reusable across competitions and future
 * in-game milestones (e.g. promotions); not FK'd to CompetitionTemplate.
 */
#[ORM\Entity(repositoryClass: RewardTemplateRepository::class)]
#[ORM\Table(name: 'reward_template')]
class RewardTemplate
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 80, unique: true)]
    private string $slug;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** @var list<array{type: string, amountPence?: int, amount?: int, assetType?: string, payload?: array}> */
    #[ORM\Column(type: 'json')]
    private array $effectsJson = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $slug, string $name, array $effectsJson = [])
    {
        $this->id = new UuidV7();
        $this->slug = $slug;
        $this->name = $name;
        $this->effectsJson = $effectsJson;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getEffectsJson(): array { return $this->effectsJson; }
    public function setEffectsJson(array $effectsJson): static { $this->effectsJson = $effectsJson; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
