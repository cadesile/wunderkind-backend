<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Concern\EditableJsonColumnTrait;
use App\Repository\TranslationKeyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single translatable string. Generic UI-copy keys (entityType/entitySlug/fieldName all
 * null) are managed directly in the admin Translations screen. Narrative-linked keys
 * (entityType/entitySlug/fieldName all set) are managed only via the owning entity's own
 * "Translations" action — see NarrativeTranslationService::ensureKeyFor().
 *
 * The link is explicit typed columns, never parsed out of $key — $key is a human-readable
 * label only (e.g. "narrative.excursion.team_bonding.title"), the same precedent CLAUDE.md
 * documents for player_1.morale vs bare player.morale: explicit slots over string convention.
 *
 * UNIQUE(entityType, entitySlug, fieldName) is a plain (non-partial) unique index. Postgres
 * treats NULLs as distinct under a plain unique index, so every generic key (all three
 * columns NULL) never collides with another generic key, while narrative-linked rows (all
 * three non-null) are still correctly deduplicated.
 */
#[ORM\Entity(repositoryClass: TranslationKeyRepository::class)]
#[ORM\Table(name: 'translation_key')]
#[ORM\UniqueConstraint(name: 'uq_translation_key_entity_field', columns: ['entity_type', 'entity_slug', 'field_name'])]
#[ORM\HasLifecycleCallbacks]
class TranslationKey
{
    use EditableJsonColumnTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $key;

    /** A TranslatableEntityType value, or null for a generic UI-copy key. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $entityType = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $entitySlug = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $fieldName = null;

    /**
     * Whether the client's rendering engine must apply plural-sensitive handling to this key.
     * Structural metadata about the key itself, identical across every language — not a
     * translated value, so it lives here rather than on Translation.
     */
    #[ORM\Column]
    private bool $isPluralSensitive = false;

    /**
     * Fragment-name => other-key-name map describing how the client composes this key's text
     * from other keys' sub-fragments. Same structural-not-translated reasoning as above.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $bandedReferences = null;

    /** @var Collection<int, Translation> */
    #[ORM\OneToMany(targetEntity: Translation::class, mappedBy: 'translationKey', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $translations;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $key = '')
    {
        $this->key          = $key;
        $this->translations = new ArrayCollection();
        $this->createdAt    = new \DateTimeImmutable();
        $this->updatedAt    = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getKey(): string { return $this->key; }
    public function setKey(string $key): void { $this->key = $key; }

    public function getEntityType(): ?string { return $this->entityType; }
    public function setEntityType(?string $entityType): void { $this->entityType = $entityType; }

    public function getEntitySlug(): ?string { return $this->entitySlug; }
    public function setEntitySlug(?string $entitySlug): void { $this->entitySlug = $entitySlug; }

    public function getFieldName(): ?string { return $this->fieldName; }
    public function setFieldName(?string $fieldName): void { $this->fieldName = $fieldName; }

    public function isGeneric(): bool { return $this->entityType === null; }

    public function isPluralSensitive(): bool { return $this->isPluralSensitive; }
    public function setIsPluralSensitive(bool $isPluralSensitive): void { $this->isPluralSensitive = $isPluralSensitive; }

    /** @return array<string, string>|null */
    public function getBandedReferences(): ?array { return $this->bandedReferences; }
    /** @param array<string, string>|null $bandedReferences */
    public function setBandedReferences(?array $bandedReferences): void { $this->bandedReferences = $bandedReferences; }

    public function getBandedReferencesJson(): string
    {
        return $this->invalidJsonInputFor('bandedReferencesJson')
            ?? (json_encode($this->bandedReferences ?? [], JSON_PRETTY_PRINT) ?: '{}');
    }

    public function setBandedReferencesJson(string $v): void
    {
        $decoded = $this->decodeJsonInput('bandedReferencesJson', $v);

        if ($decoded !== null) {
            $this->bandedReferences = $decoded === [] ? null : $decoded;
        }
    }

    /** @return Collection<int, Translation> */
    public function getTranslations(): Collection { return $this->translations; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function __toString(): string { return $this->key; }
}
