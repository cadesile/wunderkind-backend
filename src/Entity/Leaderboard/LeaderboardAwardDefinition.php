<?php

declare(strict_types=1);

namespace App\Entity\Leaderboard;

use App\Enum\Leaderboard\AwardCadence;
use App\Enum\LeaderboardCategory;
use App\Repository\Leaderboard\LeaderboardAwardDefinitionRepository;
use App\Service\Leaderboard\LeaderboardPeriod;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Admin-managed config: which leaderboard awards, how often, and to how many places.
 *
 * Nothing awards until an admin explicitly enables a definition — isEnabled defaults to
 * false, and ActivePeriodService stops emitting a disabled definition's period key
 * entirely, so no rows are even written for it.
 *
 * There is deliberately no `placements` count column. Top N is the highest `place` among
 * the child rows: one source of truth instead of two that can disagree. "Top N per
 * definition" is still satisfied — the admin adds N placement rows.
 */
#[ORM\Entity(repositoryClass: LeaderboardAwardDefinitionRepository::class)]
#[ORM\Table(name: 'leaderboard_award_definition')]
// Load-bearing, not tidiness: it is what makes (category, period_key) a sound
// non-nullable claim key on the archive. Two definitions on the same category but
// different cadences produce different period keys, so they cannot collide.
#[ORM\UniqueConstraint(name: 'uq_leaderboard_award_definition_category_cadence', columns: ['category', 'cadence'])]
#[ORM\Index(columns: ['is_enabled'], name: 'idx_lad_enabled')]
class LeaderboardAwardDefinition
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /** Frozen onto every archive this definition closes, and used in the inbox subject and the landing card. */
    #[ORM\Column(length: 100)]
    private string $name = '';

    #[ORM\Column(length: 40, enumType: LeaderboardCategory::class)]
    private LeaderboardCategory $category = LeaderboardCategory::CLUB_GOALS;

    #[ORM\Column(length: 20, enumType: AwardCadence::class)]
    private AwardCadence $cadence = AwardCadence::MONTHLY;

    /**
     * Don't award a growth below this. Otherwise a dead chart pays an idle club for
     * standing still — growth of 0 is a real and common value on a quiet board.
     */
    #[ORM\Column(name: 'minimum_score', type: 'bigint', options: ['default' => 0])]
    private int $minimumScore = 0;

    #[ORM\Column(name: 'is_enabled', options: ['default' => false])]
    private bool $isEnabled = false;

    /**
     * The monotonic anchor: the last period this definition actually closed. NULL until the
     * first close. The close job walks forward from here one period per pass, so a cron
     * outage settles each missed period in order rather than silently skipping two months.
     *
     * Deliberately NOT set when the archive claim is taken — per CLAUDE.md, never commit a
     * status ahead of the work it describes. The claim row is the gate; this is the record,
     * and it is set on the entity so it lands in the same flush as the archive itself.
     */
    #[ORM\Column(name: 'last_closed_period_key', length: 20, nullable: true)]
    private ?string $lastClosedPeriodKey = null;

    #[ORM\Column(name: 'last_closed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastClosedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * cascade persist+remove and orphanRemoval so EasyAdmin's CollectionField can add and
     * delete placement rows inline on the definition form.
     *
     * @var Collection<int, LeaderboardAwardPlacement>
     */
    #[ORM\OneToMany(mappedBy: 'definition', targetEntity: LeaderboardAwardPlacement::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['place' => 'ASC'])]
    private Collection $placements;

    public function __construct()
    {
        $this->id         = new UuidV7();
        $this->placements = new ArrayCollection();
        $this->createdAt  = new \DateTimeImmutable();
        $this->updatedAt  = $this->createdAt;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; $this->touch(); return $this; }

    public function getCategory(): LeaderboardCategory { return $this->category; }
    public function setCategory(LeaderboardCategory $category): static { $this->category = $category; $this->touch(); return $this; }

    public function getCadence(): AwardCadence { return $this->cadence; }
    public function setCadence(AwardCadence $cadence): static { $this->cadence = $cadence; $this->touch(); return $this; }

    public function getMinimumScore(): int { return $this->minimumScore; }
    public function setMinimumScore(int $minimumScore): static { $this->minimumScore = max(0, $minimumScore); $this->touch(); return $this; }

    public function isEnabled(): bool { return $this->isEnabled; }
    public function setIsEnabled(bool $isEnabled): static { $this->isEnabled = $isEnabled; $this->touch(); return $this; }

    public function getLastClosedPeriodKey(): ?string { return $this->lastClosedPeriodKey; }
    public function getLastClosedAt(): ?\DateTimeImmutable { return $this->lastClosedAt; }

    /** Set only by the close service, in the same flush as the archive the key describes. */
    public function markClosed(string $periodKey, \DateTimeImmutable $at): static
    {
        $this->lastClosedPeriodKey = $periodKey;
        $this->lastClosedAt        = $at;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, LeaderboardAwardPlacement> */
    public function getPlacements(): Collection { return $this->placements; }

    public function addPlacement(LeaderboardAwardPlacement $placement): static
    {
        if (!$this->placements->contains($placement)) {
            $this->placements->add($placement);
            $placement->setDefinition($this);
        }

        return $this;
    }

    public function removePlacement(LeaderboardAwardPlacement $placement): static
    {
        $this->placements->removeElement($placement);

        return $this;
    }

    /** Top N — the highest configured place, which is the only place it is recorded. */
    public function getDepth(): int
    {
        $depth = 0;
        foreach ($this->placements as $placement) {
            $depth = max($depth, $placement->getPlace());
        }

        return $depth;
    }

    public function getPlacementForPlace(int $place): ?LeaderboardAwardPlacement
    {
        foreach ($this->placements as $placement) {
            if ($placement->getPlace() === $place) {
                return $placement;
            }
        }

        return null;
    }

    /** The period key this definition is currently accumulating into. */
    public function currentPeriodKey(\DateTimeImmutable $at): string
    {
        return LeaderboardPeriod::keyFor($this->cadence, $at);
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : $this->category->value . ' / ' . $this->cadence->value;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
