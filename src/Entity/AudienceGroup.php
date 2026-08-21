<?php

namespace App\Entity;

use App\Enum\AudienceCriteriaType;
use App\Repository\AudienceGroupRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * A named cohort of clubs an AdminMessage can target.
 *
 * MANUAL groups hold their membership in AudienceGroupMember. DYNAMIC groups hold a
 * criteriaPayload evaluated live against a Club at poll time by AudienceCriteriaEvaluator —
 * nothing is materialised, so membership is never stale.
 */
#[ORM\Entity(repositoryClass: AudienceGroupRepository::class)]
#[ORM\Table(name: 'audience_group')]
#[ORM\UniqueConstraint(name: 'uq_audience_group_slug', columns: ['slug'])]
class AudienceGroup
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(length: 100)]
    private string $slug;

    #[ORM\Column(type: 'string', enumType: AudienceCriteriaType::class, options: ['default' => 'manual'])]
    private AudienceCriteriaType $criteriaType = AudienceCriteriaType::MANUAL;

    /**
     * Whitelisted keys only — see AudienceCriteriaEvaluator::matches(). An unrecognised key
     * makes the group match nothing rather than everything.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $criteriaPayload = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name = '', string $slug = '')
    {
        $this->id        = new UuidV7();
        $this->name      = $name;
        $this->slug      = $slug;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getCriteriaType(): AudienceCriteriaType
    {
        return $this->criteriaType;
    }

    public function setCriteriaType(AudienceCriteriaType $criteriaType): self
    {
        $this->criteriaType = $criteriaType;

        return $this;
    }

    public function getCriteriaPayload(): ?array
    {
        return $this->criteriaPayload;
    }

    public function setCriteriaPayload(?array $criteriaPayload): self
    {
        $this->criteriaPayload = $criteriaPayload;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : $this->slug;
    }
}
