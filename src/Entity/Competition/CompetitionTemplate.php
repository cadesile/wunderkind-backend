<?php

namespace App\Entity\Competition;

use App\Entity\Concern\EditableJsonColumnTrait;
use App\Enum\Competition\CompetitionDuration;
use App\Enum\TrophyColour;
use App\Repository\Competition\CompetitionTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Admin-defined blueprint. Deliberately decoupled from ActiveCompetition — a template
 * edit must never reach into a live tournament, so ActiveCompetition copies
 * entrantCapacity/durationOption at creation rather than reading this entity live.
 */
#[ORM\Entity(repositoryClass: CompetitionTemplateRepository::class)]
#[ORM\Table(name: 'competition_template')]
#[ORM\Index(columns: ['is_active'], name: 'idx_competition_template_active')]
#[ORM\HasLifecycleCallbacks]
class CompetitionTemplate
{
    use EditableJsonColumnTrait;

    public const ALLOWED_ENTRANT_CAPACITIES = [4, 8, 16, 32, 64];

    /** Minutes after the first entrant registers before auto-fill kicks in — see $autoFillSpoofEntrants. */
    public const ALLOWED_AUTO_FILL_DELAY_MINUTES = [5, 10, 20, 30, 60];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 80, unique: true)]
    private string $slug;

    #[ORM\Column(type: 'smallint')]
    private int $entrantCapacity;

    #[ORM\Column(type: 'string', enumType: CompetitionDuration::class)]
    private CompetitionDuration $durationOption;

    /** @var list<int>|null Tier semantics inverted — tier 1 = top division, per AudienceCriteriaEvaluator convention. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $allowedTiers = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $minClubReputation = 0;

    /** "Future/advanced" gate — inert unless an admin sets it above 0. */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $minClubAgeSeasons = 0;

    /** Pence. Defined for the admin UI/schema; not enforced/charged in Phase 1. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $entryFeePerRound = 0;

    /** Pence. Synthesized into a ledger_delta reward at completion — no RewardTemplate row needed. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $victorPrize = 0;

    /** Slug of the trophy silhouette, e.g. "trophy-3". Serves /images/trophies/{slug}.svg */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $trophyImage = null;

    #[ORM\Column(type: 'string', enumType: TrophyColour::class, nullable: true)]
    private ?TrophyColour $trophyColour = null;

    /** @var array<string, string>|null Keyed by round label (QF/SF/FINAL/...) -> MatchEngineIdentifier value. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $roundEngineConfig = null;

    /** Additional rewards applied to the winner on completion, alongside victorPrize. */
    #[ORM\ManyToMany(targetEntity: RewardTemplate::class)]
    #[ORM\JoinTable(name: 'competition_template_reward_template')]
    private Collection $rewardTemplates;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    /**
     * Dev/testing convenience: once the first entrant registers into an instance of this
     * template, automatically fill every remaining slot with spoof entrants (cloned from
     * that first entrant) after $autoFillDelayMinutes — so a solo tester isn't stuck waiting
     * on real registrants to fill a bracket. Filling to capacity triggers the exact same
     * auto-lock/round-1-draw path a real full house would (CompetitionRegistrationService::
     * register()), so from that point on an auto-filled instance behaves identically to a
     * genuinely full one. See CompetitionAutoFillService.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $autoFillSpoofEntrants = false;

    #[ORM\Column(type: 'smallint', options: ['default' => 15])]
    private int $autoFillDelayMinutes = 20;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name = '', string $slug = '', int $entrantCapacity = 4, CompetitionDuration $durationOption = CompetitionDuration::ONE_DAY)
    {
        $this->id              = new UuidV7();
        $this->name            = $name;
        $this->slug            = $slug;
        $this->entrantCapacity = $entrantCapacity;
        $this->durationOption  = $durationOption;
        $this->rewardTemplates = new ArrayCollection();
        $this->createdAt       = new \DateTimeImmutable();
        $this->updatedAt       = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }

    public function getEntrantCapacity(): int { return $this->entrantCapacity; }
    public function setEntrantCapacity(int $entrantCapacity): static { $this->entrantCapacity = $entrantCapacity; return $this; }

    public function getDurationOption(): CompetitionDuration { return $this->durationOption; }
    public function setDurationOption(CompetitionDuration $durationOption): static { $this->durationOption = $durationOption; return $this; }

    public function getAllowedTiers(): ?array { return $this->allowedTiers; }
    public function setAllowedTiers(?array $allowedTiers): static { $this->allowedTiers = $allowedTiers; return $this; }

    /** Virtual property for the admin form — serialises allowedTiers as a JSON string. Empty/null means "no tier restriction". */
    public function getAllowedTiersJson(): string
    {
        if (($invalid = $this->invalidJsonInputFor('allowedTiersJson')) !== null) {
            return $invalid;
        }

        return $this->allowedTiers !== null
            ? (json_encode($this->allowedTiers, JSON_PRETTY_PRINT) ?: '[]')
            : '';
    }

    public function setAllowedTiersJson(?string $json): void
    {
        $trimmed = trim($json ?? '');
        if ($trimmed === '') {
            unset($this->invalidJsonInput['allowedTiersJson']);
            $this->allowedTiers = null;
            return;
        }

        $decoded = $this->decodeJsonInput('allowedTiersJson', $trimmed);
        if ($decoded !== null) {
            $this->allowedTiers = array_map('intval', $decoded);
        }
    }

    public function getMinClubReputation(): int { return $this->minClubReputation; }
    public function setMinClubReputation(int $minClubReputation): static { $this->minClubReputation = $minClubReputation; return $this; }

    public function getMinClubAgeSeasons(): int { return $this->minClubAgeSeasons; }
    public function setMinClubAgeSeasons(int $minClubAgeSeasons): static { $this->minClubAgeSeasons = $minClubAgeSeasons; return $this; }

    public function getEntryFeePerRound(): int { return $this->entryFeePerRound; }
    public function setEntryFeePerRound(int $entryFeePerRound): static { $this->entryFeePerRound = $entryFeePerRound; return $this; }

    public function getVictorPrize(): int { return $this->victorPrize; }
    public function setVictorPrize(int $victorPrize): static { $this->victorPrize = $victorPrize; return $this; }

    public function getTrophyImage(): ?string { return $this->trophyImage; }
    public function setTrophyImage(?string $v): static { $this->trophyImage = $v; return $this; }

    public function getTrophyColour(): ?TrophyColour { return $this->trophyColour; }
    public function setTrophyColour(?TrophyColour $v): static { $this->trophyColour = $v; return $this; }

    public function getRoundEngineConfig(): ?array { return $this->roundEngineConfig; }
    public function setRoundEngineConfig(?array $roundEngineConfig): static { $this->roundEngineConfig = $roundEngineConfig; return $this; }

    /**
     * Virtual property for the admin form — serialises roundEngineConfig as a JSON
     * string. Keys are round labels (QF/SF/FINAL/R16/R32/R64) or "default"; values are
     * MatchEngineIdentifier values (deterministic/ai_assisted/ai_narrative).
     */
    public function getRoundEngineConfigJson(): string
    {
        if (($invalid = $this->invalidJsonInputFor('roundEngineConfigJson')) !== null) {
            return $invalid;
        }

        return $this->roundEngineConfig !== null
            ? (json_encode($this->roundEngineConfig, JSON_PRETTY_PRINT) ?: '{}')
            : '';
    }

    public function setRoundEngineConfigJson(?string $json): void
    {
        $trimmed = trim($json ?? '');
        if ($trimmed === '') {
            unset($this->invalidJsonInput['roundEngineConfigJson']);
            $this->roundEngineConfig = null;
            return;
        }

        $decoded = $this->decodeJsonInput('roundEngineConfigJson', $trimmed);
        if ($decoded !== null) {
            $this->roundEngineConfig = $decoded;
        }
    }

    /** @return Collection<int, RewardTemplate> */
    public function getRewardTemplates(): Collection { return $this->rewardTemplates; }

    public function addRewardTemplate(RewardTemplate $rewardTemplate): static
    {
        if (!$this->rewardTemplates->contains($rewardTemplate)) {
            $this->rewardTemplates->add($rewardTemplate);
        }
        return $this;
    }

    public function removeRewardTemplate(RewardTemplate $rewardTemplate): static
    {
        $this->rewardTemplates->removeElement($rewardTemplate);
        return $this;
    }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function isAutoFillSpoofEntrants(): bool { return $this->autoFillSpoofEntrants; }
    public function setAutoFillSpoofEntrants(bool $autoFillSpoofEntrants): static { $this->autoFillSpoofEntrants = $autoFillSpoofEntrants; return $this; }

    public function getAutoFillDelayMinutes(): int { return $this->autoFillDelayMinutes; }
    public function setAutoFillDelayMinutes(int $autoFillDelayMinutes): static { $this->autoFillDelayMinutes = $autoFillDelayMinutes; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function validate(): void
    {
        if (!in_array($this->entrantCapacity, self::ALLOWED_ENTRANT_CAPACITIES, true)) {
            throw new \InvalidArgumentException(
                'entrantCapacity must be one of: ' . implode(', ', self::ALLOWED_ENTRANT_CAPACITIES)
            );
        }
        if ($this->minClubReputation < 0 || $this->minClubReputation > 100) {
            throw new \InvalidArgumentException('minClubReputation must be between 0 and 100');
        }
        if (!in_array($this->autoFillDelayMinutes, self::ALLOWED_AUTO_FILL_DELAY_MINUTES, true)) {
            throw new \InvalidArgumentException(
                'autoFillDelayMinutes must be one of: ' . implode(', ', self::ALLOWED_AUTO_FILL_DELAY_MINUTES)
            );
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string { return $this->name; }
}
