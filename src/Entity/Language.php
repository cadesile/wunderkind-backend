<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LanguageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A language the admin has configured for translation. Exactly one row should have
 * isDefault = true at any time — enforced in LanguageCrudController (unsetting every other
 * row on save), not a DB constraint, since this is low-frequency single-admin CRUD rather
 * than a concurrency-sensitive path.
 *
 * The default language's text is never mirrored into Translation rows for narrative content
 * (GameEventTemplate/FacilityTemplate/Excursion) — it is always read live off the owning
 * entity. See NarrativeTranslationService.
 */
#[ORM\Entity(repositoryClass: LanguageRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Language
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Stable identifier used by clients, e.g. 'en', 'fr'. Treated as immutable once shipped. */
    #[ORM\Column(length: 5, unique: true)]
    private string $code;

    #[ORM\Column(length: 100)]
    private string $name;

    /** Disabled languages are hidden from /api/languages and admin language pickers, but their Translation rows are kept. */
    #[ORM\Column]
    private bool $isEnabled = true;

    /** The fallback language for narrative content and for untranslated generic keys. */
    #[ORM\Column]
    private bool $isDefault = false;

    /** Display order, ascending, for /api/languages and admin pickers. */
    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code = '', string $name = '')
    {
        $this->code      = $code;
        $this->name      = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): void { $this->code = $code; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

    public function isEnabled(): bool { return $this->isEnabled; }
    public function setIsEnabled(bool $isEnabled): void { $this->isEnabled = $isEnabled; }

    public function isDefault(): bool { return $this->isDefault; }
    public function setIsDefault(bool $isDefault): void { $this->isDefault = $isDefault; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): void { $this->sortOrder = $sortOrder; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
