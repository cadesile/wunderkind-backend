<?php

namespace App\Entity;

use App\Enum\SocialPlatform;
use App\Enum\StatCategory;
use App\Enum\StatsPeriod;
use App\Repository\SocialPostTemplateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SocialPostTemplateRepository::class)]
#[ORM\Table(name: 'social_post_template')]
#[ORM\UniqueConstraint(name: 'uq_social_post_template_category_platform_period', columns: ['category', 'platform', 'period'])]
#[UniqueEntity(
    fields: ['category', 'platform', 'period'],
    message: 'A template for this category, platform, and period already exists.',
)]
class SocialPostTemplate
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(type: 'string', enumType: StatCategory::class)]
    private StatCategory $category;

    #[ORM\Column(type: 'string', enumType: SocialPlatform::class)]
    private SocialPlatform $platform;

    /** Which CommunityStatsService period this template's data is pulled from. */
    #[ORM\Column(type: 'string', enumType: StatsPeriod::class)]
    private StatsPeriod $period;

    /**
     * Template string. Placeholders:
     *  - {{clubName}}       club name
     *  - {{value}}          raw rounded int (unformatted) — used by the original 4 categories
     *  - {{statValue}}      per-category formatted figure (comma-separated; pence->pounds
     *                       for TRANSFER_SPLURGE; 1 decimal place for FORTRESS_DEFENCE)
     *  - {{secondaryValue}} secondary figure — only BEST_FORM populates it (goal
     *                       difference); empty string for every other category
     *  - {{playerName}}     individual player name — only SUPER_STRIKER populates it;
     *                       empty string for every other category
     *  - {{rank}}           1-indexed rank
     *  - {{period}}         human-readable period phrase, e.g. "this week", "over the
     *                       last 24 hours" — not the raw enum value
     *  - {{categoryLabel}}  human-readable category phrase
     */
    #[ORM\Column(type: 'text')]
    private string $bodyTemplate;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        StatCategory $category = StatCategory::MOST_TRANSFERS,
        SocialPlatform $platform = SocialPlatform::FACEBOOK,
        StatsPeriod $period = StatsPeriod::ALL,
        string $bodyTemplate = '',
    ) {
        $this->id           = new UuidV7();
        $this->category     = $category;
        $this->platform     = $platform;
        $this->period       = $period;
        $this->bodyTemplate = $bodyTemplate;
        $this->createdAt    = new \DateTimeImmutable();
        $this->updatedAt    = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getCategory(): StatCategory { return $this->category; }
    public function setCategory(StatCategory $v): static { $this->category = $v; $this->touch(); return $this; }

    public function getPlatform(): SocialPlatform { return $this->platform; }
    public function setPlatform(SocialPlatform $v): static { $this->platform = $v; $this->touch(); return $this; }

    public function getPeriod(): StatsPeriod { return $this->period; }
    public function setPeriod(StatsPeriod $v): static { $this->period = $v; $this->touch(); return $this; }

    public function getBodyTemplate(): string { return $this->bodyTemplate; }
    public function setBodyTemplate(string $v): static { $this->bodyTemplate = $v; $this->touch(); return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; $this->touch(); return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    /**
     * X (Twitter) posts are capped at 280 characters. This is a conservative
     * lower-bound check against the literal template text (including
     * unexpanded {{token}} placeholders) — token substitution only adds
     * length, so a template that already exceeds 280 chars before
     * substitution can never fit after it.
     */
    #[Assert\Callback]
    public function validateTwitterLength(ExecutionContextInterface $context): void
    {
        if ($this->platform === SocialPlatform::TWITTER && mb_strlen($this->bodyTemplate) > 280) {
            $context->buildViolation('X (Twitter) templates must be 280 characters or fewer (before token substitution).')
                ->atPath('bodyTemplate')
                ->addViolation();
        }
    }
}
