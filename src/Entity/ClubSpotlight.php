<?php

namespace App\Entity;

use App\Repository\ClubSpotlightRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Singleton row holding the currently-featured club for the landing page's
 * "Club Spotlight" section. Refreshed every 12h by app:spotlight:generate (see
 * ClubSpotlightService), which picks one of the 5 most active clubs at random
 * and denormalizes everything the template needs onto this row — same pattern
 * as LiveTelemetrySnapshot, so the landing page never joins/queries Club at
 * render time.
 */
#[ORM\Entity(repositoryClass: ClubSpotlightRepository::class)]
class ClubSpotlight
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $clubName = '';

    #[ORM\Column(type: 'bigint', options: ['unsigned' => true, 'default' => 0])]
    private int $clubReputation = 0;

    #[ORM\Column(type: 'bigint', options: ['unsigned' => true, 'default' => 0])]
    private int $clubTotalCareerEarnings = 0;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $homeKitConfig = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $awayKitConfig = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $badgeConfig = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ownerName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ownerNationality = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $ownerAppearance = null;

    /**
     * {north, east, south, west, shopLevel, museumLevel, carparkLevel, ...} —
     * built from the club's real ClubFacility levels. See
     * ClubSpotlightService::buildStadiumConfig() for the FacilityTemplate
     * (1-5) -> StadiumConfig (1-10) stand-level mapping.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $stadiumConfig = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $windowStartsAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $windowEndsAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $generatedAt;

    public function __construct()
    {
        $this->windowStartsAt = new \DateTimeImmutable();
        $this->windowEndsAt   = new \DateTimeImmutable();
        $this->generatedAt    = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getClubName(): string { return $this->clubName; }
    public function getClubReputation(): int { return $this->clubReputation; }
    public function getClubTotalCareerEarnings(): int { return $this->clubTotalCareerEarnings; }
    public function getHomeKitConfig(): ?array { return $this->homeKitConfig; }
    public function getAwayKitConfig(): ?array { return $this->awayKitConfig; }
    public function getBadgeConfig(): ?array { return $this->badgeConfig; }
    public function getOwnerName(): ?string { return $this->ownerName; }
    public function getOwnerNationality(): ?string { return $this->ownerNationality; }
    public function getOwnerAppearance(): ?array { return $this->ownerAppearance; }
    public function getStadiumConfig(): ?array { return $this->stadiumConfig; }
    public function getWindowStartsAt(): \DateTimeImmutable { return $this->windowStartsAt; }
    public function getWindowEndsAt(): \DateTimeImmutable { return $this->windowEndsAt; }
    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }

    public function update(
        string $clubName,
        int $clubReputation,
        int $clubTotalCareerEarnings,
        ?array $homeKitConfig,
        ?array $awayKitConfig,
        ?array $badgeConfig,
        ?string $ownerName,
        ?string $ownerNationality,
        ?array $ownerAppearance,
        ?array $stadiumConfig,
        \DateTimeImmutable $windowStartsAt,
        \DateTimeImmutable $windowEndsAt,
    ): void {
        $this->clubName                = $clubName;
        $this->clubReputation          = $clubReputation;
        $this->clubTotalCareerEarnings = $clubTotalCareerEarnings;
        $this->homeKitConfig           = $homeKitConfig;
        $this->awayKitConfig           = $awayKitConfig;
        $this->badgeConfig             = $badgeConfig;
        $this->ownerName               = $ownerName;
        $this->ownerNationality        = $ownerNationality;
        $this->ownerAppearance         = $ownerAppearance;
        $this->stadiumConfig           = $stadiumConfig;
        $this->windowStartsAt          = $windowStartsAt;
        $this->windowEndsAt            = $windowEndsAt;
        $this->generatedAt             = new \DateTimeImmutable();
    }
}
