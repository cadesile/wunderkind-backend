<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExcursionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A bookable club excursion — a trip or activity the manager pays for to lift
 * squad morale and dressing-room cohesion. Replaces the app's old static
 * "group session" buttons.
 *
 * The client mirrors this shape in src/types/excursion.ts and keeps a bundled
 * fallback copy in src/constants/excursions.ts. Field names here must match the
 * JSON the API emits, because a mismatch fails silently on the client: the
 * store simply keeps its fallback and the catalogue never appears to update.
 */
#[ORM\Entity(repositoryClass: ExcursionRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Excursion
{
    public const AUDIENCE_PLAYERS = 'players';
    public const AUDIENCE_STAFF   = 'staff';
    public const AUDIENCE_BOTH    = 'both';

    public const AUDIENCES = [
        'Players only'   => self::AUDIENCE_PLAYERS,
        'Staff only'     => self::AUDIENCE_STAFF,
        'Players + staff' => self::AUDIENCE_BOTH,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Stable identifier used by the client; never change it once shipped. */
    #[ORM\Column(length: 100, unique: true)]
    private string $slug;

    #[ORM\Column(length: 150)]
    private string $title;

    /** Flavour text shown on the booking card. */
    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    /** Relative upload path, converted to an absolute URL by the API. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagePath = null;

    /** Pence, PER ATTENDEE — the client multiplies by headcount at booking. */
    #[ORM\Column]
    private int $costPerPersonPence = 0;

    /** 1–100. Size of the morale payoff when the trip goes well. */
    #[ORM\Column]
    private int $effectValue = 50;

    /**
     * 1–10. Chance of friction between a staff member and a player, applied as
     * negativeFrequency/10 per attendee. Friction erodes the payoff rather than
     * inflicting a separate penalty — a bad trip is a wasted one.
     */
    #[ORM\Column]
    private int $negativeFrequency = 5;

    #[ORM\Column(length: 20)]
    private string $targetAudience = self::AUDIENCE_BOTH;

    /** Bookable at any time, but only resolves during the close season. */
    #[ORM\Column]
    private bool $postSeasonOnly = false;

    /** Minimum weeks before the same excursion can be booked again. */
    #[ORM\Column]
    private int $cooldownWeeks = 4;

    /** Inactive excursions are filtered out of the API response. */
    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $slug = '', string $title = '', string $body = '')
    {
        $this->slug      = $slug;
        $this->title     = $title;
        $this->body      = $body;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = $slug; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = $title; }

    public function getBody(): string { return $this->body; }
    public function setBody(string $body): void { $this->body = $body; }

    public function getImagePath(): ?string { return $this->imagePath; }
    public function setImagePath(?string $imagePath): void { $this->imagePath = $imagePath; }

    public function getCostPerPersonPence(): int { return $this->costPerPersonPence; }
    public function setCostPerPersonPence(int $cost): void { $this->costPerPersonPence = $cost; }

    public function getEffectValue(): int { return $this->effectValue; }
    public function setEffectValue(int $effectValue): void { $this->effectValue = $effectValue; }

    public function getNegativeFrequency(): int { return $this->negativeFrequency; }
    public function setNegativeFrequency(int $negativeFrequency): void { $this->negativeFrequency = $negativeFrequency; }

    public function getTargetAudience(): string { return $this->targetAudience; }
    public function setTargetAudience(string $targetAudience): void { $this->targetAudience = $targetAudience; }

    public function isPostSeasonOnly(): bool { return $this->postSeasonOnly; }
    public function setPostSeasonOnly(bool $postSeasonOnly): void { $this->postSeasonOnly = $postSeasonOnly; }

    public function getCooldownWeeks(): int { return $this->cooldownWeeks; }
    public function setCooldownWeeks(int $cooldownWeeks): void { $this->cooldownWeeks = $cooldownWeeks; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
