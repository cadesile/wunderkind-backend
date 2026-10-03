<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TranslationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One language's value for one TranslationKey. An empty string is a deliberate, valid
 * translation — "untranslated" is the absence of a row, never an empty value.
 *
 * There is deliberately no row for a narrative key's default (EN) language — see
 * NarrativeTranslationService. Generic UI-copy keys have no live source, so their default-
 * language value is an ordinary stored row like any other language.
 */
#[ORM\Entity(repositoryClass: TranslationRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_translation_key_language', columns: ['translation_key_id', 'language_id'])]
#[ORM\HasLifecycleCallbacks]
class Translation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TranslationKey::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TranslationKey $translationKey;

    #[ORM\ManyToOne(targetEntity: Language::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Language $language;

    #[ORM\Column(type: 'text')]
    private string $value = '';

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * $translationKey/$language default to null (rather than being required) so EasyAdmin's
     * "New" action — which instantiates a blank entity with no constructor args before
     * binding the submitted form onto it — can construct one at all. Left unassigned (not
     * forced to a dummy value) when omitted; the admin form's own required-association
     * validation, not this constructor, is what actually enforces they get set before save.
     */
    public function __construct(?TranslationKey $translationKey = null, ?Language $language = null, string $value = '')
    {
        if ($translationKey !== null) {
            $this->translationKey = $translationKey;
        }
        if ($language !== null) {
            $this->language = $language;
        }
        $this->value     = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTranslationKey(): TranslationKey { return $this->translationKey; }
    public function setTranslationKey(TranslationKey $translationKey): void { $this->translationKey = $translationKey; }

    public function getLanguage(): Language { return $this->language; }
    public function setLanguage(Language $language): void { $this->language = $language; }

    public function getValue(): string { return $this->value; }
    public function setValue(string $value): void { $this->value = $value; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
