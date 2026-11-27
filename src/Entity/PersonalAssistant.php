<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PersonalAssistantTone;
use App\Repository\PersonalAssistantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A Personal Assistant personality the owner can choose (Courtney, 2026-11-27).
 *
 * OWNER-LEVEL CONFIG, NOT GAME STATE. Nothing here is keyed on a club or a save: the app stores
 * the chosen `slug` in its owner database and the same PA follows the owner across every save.
 * It replaces the generated per-save assistant the app used to make up.
 *
 * OPEN-ENDED BY DESIGN. Launch ships three (seeded by Version20261127100000), but admins add more
 * from the CRUD screen without code. Nothing may assume exactly three, or a gender mix.
 *
 * `personalityPrompt` NEVER LEAVES THE SERVER. It is the voice brief sent to the AI with every
 * advice call for this PA (see PaAdvicePromptBuilder). Game-config serves `description` instead
 * — see toConfigArray(), which is the only shape the app ever sees.
 *
 * NO HARD DELETE. A PA may be the stored choice of any number of owners, so the CRUD hides delete
 * and an admin deactivates instead; the app falls back to its default when a slug disappears.
 */
#[ORM\Entity(repositoryClass: PersonalAssistantRepository::class)]
#[ORM\Table(name: 'personal_assistant')]
#[ORM\UniqueConstraint(name: 'uniq_personal_assistant_slug', columns: ['slug'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'Another Personal Assistant already uses this slug.')]
class PersonalAssistant
{
    public const GENDERS  = ['male', 'female'];
    public const PRONOUNS = ['he', 'she', 'they'];

    /** Long enough for a rich voice brief, short enough that it stays a brief. */
    public const PERSONALITY_PROMPT_MAX = 2000;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /**
     * Stable key the app stores as the owner's choice, e.g. `pa-marcus`. Immutable once created:
     * renaming it would silently reset every owner who picked this PA.
     */
    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'Lowercase letters, digits and single hyphens only, e.g. pa-marcus.')]
    #[Assert\Length(max: 60)]
    private string $slug;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 60)]
    private string $name;

    #[ORM\Column(length: 10)]
    #[Assert\Choice(choices: self::GENDERS)]
    private string $gender;

    /** Used by the app's template text ("he says", "she's flagged"). */
    #[ORM\Column(length: 10)]
    #[Assert\Choice(choices: self::PRONOUNS)]
    private string $pronoun;

    /** Seed for the app's procedural sprite: generateExecutiveAppearance(seed). */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $appearanceSeed;

    /**
     * Optional partial SpriteConfig laid over the seeded sprite, e.g. {"glasses": true}.
     * A flat object of scalars; the app ignores keys it does not know.
     *
     * @var array<string, scalar>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $appearanceOverrides = null;

    /** Player-facing, shown in the app's picker. 1–2 sentences. */
    #[ORM\Column(length: 300)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 300)]
    private string $description;

    /** Admin-only voice brief sent with every AI advice call. Never served to the app. */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::PERSONALITY_PROMPT_MAX)]
    private string $personalityPrompt;

    #[ORM\Column(length: 20, enumType: PersonalAssistantTone::class)]
    private PersonalAssistantTone $tone;

    /** Short in-character "away" line shown when the AI wording is unavailable. */
    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200)]
    private ?string $outOfOfficeMessage = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $slug = '',
        string $name = '',
        string $gender = 'female',
        string $pronoun = 'she',
        string $appearanceSeed = '',
        string $description = '',
        string $personalityPrompt = '',
        PersonalAssistantTone $tone = PersonalAssistantTone::Precise,
    ) {
        $this->id                = new UuidV7();
        $this->slug              = $slug;
        $this->name              = $name;
        $this->gender            = $gender;
        $this->pronoun           = $pronoun;
        $this->appearanceSeed    = $appearanceSeed;
        $this->description       = $description;
        $this->personalityPrompt = $personalityPrompt;
        $this->tone              = $tone;
        $this->createdAt         = new \DateTimeImmutable();
        $this->updatedAt         = $this->createdAt;
    }

    #[Assert\Callback]
    public function validateAppearanceOverrides(ExecutionContextInterface $context): void
    {
        if ($this->appearanceOverrides === null) {
            return;
        }
        if (array_is_list($this->appearanceOverrides) && $this->appearanceOverrides !== []) {
            $context->buildViolation('Must be a JSON object, e.g. {"glasses": true}.')
                ->atPath('appearanceOverrides')->addViolation();

            return;
        }
        foreach ($this->appearanceOverrides as $key => $value) {
            if (!\is_string($key) || !\is_scalar($value)) {
                $context->buildViolation('Every value must be a string, number or true/false (no nested objects).')
                    ->atPath('appearanceOverrides')->addViolation();

                return;
            }
        }
    }

    /**
     * The shape served in GET /api/game-config `personalAssistants`. Deliberately an allowlist:
     * `personalityPrompt` must never appear here.
     *
     * @return array<string, mixed>
     */
    public function toConfigArray(): array
    {
        return [
            'id'                  => (string) $this->id,
            'slug'                => $this->slug,
            'name'                => $this->name,
            'gender'              => $this->gender,
            'pronoun'             => $this->pronoun,
            'appearanceSeed'      => $this->appearanceSeed,
            'appearanceOverrides' => $this->appearanceOverrides,
            'description'         => $this->description,
            'tone'                => $this->tone->value,
            'outOfOfficeMessage'  => $this->outOfOfficeMessage,
            'sortOrder'           => $this->sortOrder,
        ];
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = $slug; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

    public function getGender(): string { return $this->gender; }
    public function setGender(string $gender): void { $this->gender = $gender; }

    public function getPronoun(): string { return $this->pronoun; }
    public function setPronoun(string $pronoun): void { $this->pronoun = $pronoun; }

    public function getAppearanceSeed(): string { return $this->appearanceSeed; }
    public function setAppearanceSeed(string $appearanceSeed): void { $this->appearanceSeed = $appearanceSeed; }

    /** @return array<string, scalar>|null */
    public function getAppearanceOverrides(): ?array { return $this->appearanceOverrides; }
    /** @param array<string, scalar>|null $overrides */
    public function setAppearanceOverrides(?array $overrides): void { $this->appearanceOverrides = $overrides === [] ? null : $overrides; }

    /** EasyAdmin edits the overrides as JSON text. */
    public function getAppearanceOverridesJson(): string
    {
        return $this->appearanceOverrides === null ? '' : (string) json_encode($this->appearanceOverrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function setAppearanceOverridesJson(?string $json): void
    {
        $json = trim((string) $json);
        if ($json === '') {
            $this->appearanceOverrides = null;

            return;
        }
        $decoded = json_decode($json, true);
        // Invalid JSON becomes a list so validateAppearanceOverrides() reports it, rather than
        // silently dropping what the admin typed.
        $this->appearanceOverrides = \is_array($decoded) ? $decoded : [$json];
    }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): void { $this->description = $description; }

    public function getPersonalityPrompt(): string { return $this->personalityPrompt; }
    public function setPersonalityPrompt(string $prompt): void { $this->personalityPrompt = $prompt; }

    public function getTone(): PersonalAssistantTone { return $this->tone; }
    public function setTone(PersonalAssistantTone $tone): void { $this->tone = $tone; }

    public function getOutOfOfficeMessage(): ?string { return $this->outOfOfficeMessage; }
    public function setOutOfOfficeMessage(?string $message): void { $this->outOfOfficeMessage = $message === '' ? null : $message; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): void { $this->sortOrder = $sortOrder; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): void { $this->isActive = $isActive; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void { $this->updatedAt = $updatedAt; }
}
