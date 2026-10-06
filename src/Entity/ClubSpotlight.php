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

    /**
     * Pence actually drawn by the owner against this club (UserLedger
     * DIVIDEND_DRAW entries) — deliberately not Club::$totalCareerEarnings,
     * which includes every income source, not money the owner has drawn out.
     */
    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $dividendDrawsPence = 0;

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

    /**
     * Last few MatchResult rows for the club, newest first.
     *
     * @var array<int, array{opponent: string, scoreFor: int, scoreAgainst: int, week: int, result: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $recentFixtures = [];

    /**
     * Last few Transfer rows for the club, newest first.
     *
     * @var array<int, array{playerName: string, feePence: int, direction: string, counterpartyClub: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $recentTransfers = [];

    /**
     * The club's standout PlayerCareerStat this season — there is no persisted
     * per-player match rating anywhere server-side (match reports with
     * individual ratings are a client-side simulation artifact), so this is
     * goals+assists ranked, the closest real signal available. Null if the
     * club has no PlayerCareerStat rows yet.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $topPerformerName = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $topPerformerGoals = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $topPerformerAssists = 0;

    /** Full merged sprite config (personal traits + club kit colors) — see PlayerCareerStat::toFullAppearanceConfig(). Null if the player has never reported an appearanceConfig. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $topPerformerAppearanceConfig = null;

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
    public function getDividendDrawsPence(): int { return $this->dividendDrawsPence; }
    public function getHomeKitConfig(): ?array { return $this->homeKitConfig; }
    public function getAwayKitConfig(): ?array { return $this->awayKitConfig; }
    public function getBadgeConfig(): ?array { return $this->badgeConfig; }
    public function getOwnerName(): ?string { return $this->ownerName; }
    public function getOwnerNationality(): ?string { return $this->ownerNationality; }
    public function getOwnerAppearance(): ?array { return $this->ownerAppearance; }
    public function getStadiumConfig(): ?array { return $this->stadiumConfig; }
    /** @return array<int, array{opponent: string, scoreFor: int, scoreAgainst: int, week: int, result: string}> */
    public function getRecentFixtures(): array { return $this->recentFixtures; }
    /** @return array<int, array{playerName: string, feePence: int, direction: string, counterpartyClub: string}> */
    public function getRecentTransfers(): array { return $this->recentTransfers; }
    public function getTopPerformerName(): ?string { return $this->topPerformerName; }
    public function getTopPerformerGoals(): int { return $this->topPerformerGoals; }
    public function getTopPerformerAssists(): int { return $this->topPerformerAssists; }
    public function getTopPerformerAppearanceConfig(): ?array { return $this->topPerformerAppearanceConfig; }
    public function getWindowStartsAt(): \DateTimeImmutable { return $this->windowStartsAt; }
    public function getWindowEndsAt(): \DateTimeImmutable { return $this->windowEndsAt; }
    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }

    /**
     * @param array<int, array{opponent: string, scoreFor: int, scoreAgainst: int, week: int, result: string}> $recentFixtures
     * @param array<int, array{playerName: string, feePence: int, direction: string, counterpartyClub: string}> $recentTransfers
     */
    public function update(
        string $clubName,
        int $clubReputation,
        int $dividendDrawsPence,
        ?array $homeKitConfig,
        ?array $awayKitConfig,
        ?array $badgeConfig,
        ?string $ownerName,
        ?string $ownerNationality,
        ?array $ownerAppearance,
        ?array $stadiumConfig,
        array $recentFixtures,
        array $recentTransfers,
        ?string $topPerformerName,
        int $topPerformerGoals,
        int $topPerformerAssists,
        ?array $topPerformerAppearanceConfig,
        \DateTimeImmutable $windowStartsAt,
        \DateTimeImmutable $windowEndsAt,
    ): void {
        $this->clubName                     = $clubName;
        $this->clubReputation               = $clubReputation;
        $this->dividendDrawsPence           = $dividendDrawsPence;
        $this->homeKitConfig                = $homeKitConfig;
        $this->awayKitConfig                = $awayKitConfig;
        $this->badgeConfig                  = $badgeConfig;
        $this->ownerName                    = $ownerName;
        $this->ownerNationality             = $ownerNationality;
        $this->ownerAppearance              = $ownerAppearance;
        $this->stadiumConfig                = $stadiumConfig;
        $this->recentFixtures               = $recentFixtures;
        $this->recentTransfers              = $recentTransfers;
        $this->topPerformerName             = $topPerformerName;
        $this->topPerformerGoals            = $topPerformerGoals;
        $this->topPerformerAssists          = $topPerformerAssists;
        $this->topPerformerAppearanceConfig = $topPerformerAppearanceConfig;
        $this->windowStartsAt               = $windowStartsAt;
        $this->windowEndsAt                 = $windowEndsAt;
        $this->generatedAt                  = new \DateTimeImmutable();
    }
}
