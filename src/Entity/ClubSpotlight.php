<?php

namespace App\Entity;

use App\Repository\ClubSpotlightRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Singleton row holding the currently-featured club for the landing page's
 * "Club Spotlight" section. Refreshed every 3h by app:spotlight:generate (see
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

    /**
     * The featured Club's id, denormalized as a plain string — deliberately NOT
     * a FK/relation. This row has to outlive the club it describes (same reason
     * every other field here is a copy, not a join), so a real association
     * would either block the club's deletion or null this out.
     *
     * Exists so ClubSpotlightService::selectNew() can recognise the incumbent
     * exactly. Club names are not unique — production has six "London FC" — so
     * matching on clubName alone made a different club with the same name look
     * like the one already featured. Nullable only because rows written before
     * the column existed have no id to backfill from; selectNew() falls back to
     * the name for those, which self-heals on the first refresh.
     */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $clubId = null;

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
     * Every facility site the club has, each a ready-to-render sprite config:
     * {training, medical, scouting, stadium}. The stadium is duplicated here so
     * the panel can read one shape for all four, while $stadiumConfig above stays
     * exactly as it was for the existing Club Spotlight card — changing that key
     * would break a contract landing.js already consumes.
     *
     * Built by ClubSpotlightService::buildFacilityConfigs() from the club's own
     * ClubFacility rows, with slugs resolved through the generated
     * FacilitySpriteMap. Null for spotlights generated before this column existed;
     * the panel falls back rather than drawing demo defaults.
     *
     * @var array<string, array<string, mixed>>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $facilities = null;

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

    /**
     * Titles this club has won, one entry per league tier, richest tier first:
     * {tier, titles, trophy, leagueName}, where `trophy` is the trophy sprite's
     * own config (see League::$trophy).
     *
     * The trophy is NOT a property of the title — it is read off the League row
     * for (the club's country, that tier), so a club's silverware always renders
     * with whatever trophy an admin has configured for that division today.
     * League::$trophy is nullable, so an entry can legitimately carry a title
     * count and no trophy; the template renders a count-only chip rather than
     * dropping the title. Rows generated before the trophy component was replaced
     * still carry the retired trophyImage/trophyColour keys instead — the template
     * reads `trophy` defensively and such rows age out within a spotlight cycle.
     *
     * @var array<int, array{tier: int, titles: int, trophy: ?array, leagueName: ?string}>
     */
    #[ORM\Column(type: 'json')]
    private array $trophies = [];

    /**
     * When the club was founded — Club::$createdAt, denormalized like every other
     * field here. There is no separate "established" column on Club; the row's
     * creation IS the founding, since a Club row only exists once an owner has
     * gone through POST /api/club/initialize.
     *
     * Nullable only so rows written before this column existed render without it.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $establishedAt = null;

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
    public function getClubId(): ?string { return $this->clubId; }
    public function getClubReputation(): int { return $this->clubReputation; }
    public function getDividendDrawsPence(): int { return $this->dividendDrawsPence; }
    public function getHomeKitConfig(): ?array { return $this->homeKitConfig; }
    public function getAwayKitConfig(): ?array { return $this->awayKitConfig; }
    public function getBadgeConfig(): ?array { return $this->badgeConfig; }
    public function getOwnerName(): ?string { return $this->ownerName; }
    public function getOwnerNationality(): ?string { return $this->ownerNationality; }
    public function getOwnerAppearance(): ?array { return $this->ownerAppearance; }
    public function getStadiumConfig(): ?array { return $this->stadiumConfig; }
    /** @return array<string, array<string, mixed>>|null */
    public function getFacilities(): ?array { return $this->facilities; }
    /** @return array<int, array{opponent: string, scoreFor: int, scoreAgainst: int, week: int, result: string}> */
    public function getRecentFixtures(): array { return $this->recentFixtures; }
    /** @return array<int, array{playerName: string, feePence: int, direction: string, counterpartyClub: string}> */
    public function getRecentTransfers(): array { return $this->recentTransfers; }
    public function getTopPerformerName(): ?string { return $this->topPerformerName; }
    public function getTopPerformerGoals(): int { return $this->topPerformerGoals; }
    public function getTopPerformerAssists(): int { return $this->topPerformerAssists; }
    public function getTopPerformerAppearanceConfig(): ?array { return $this->topPerformerAppearanceConfig; }
    /** @return array<int, array{tier: int, titles: int, trophy: ?array, leagueName: ?string}> */
    public function getTrophies(): array { return $this->trophies; }
    public function getEstablishedAt(): ?\DateTimeImmutable { return $this->establishedAt; }
    /** Total titles across every tier — the headline figure, so the template doesn't sum in Twig. */
    public function getTotalTrophies(): int { return array_sum(array_column($this->trophies, 'titles')); }
    public function getWindowStartsAt(): \DateTimeImmutable { return $this->windowStartsAt; }
    public function getWindowEndsAt(): \DateTimeImmutable { return $this->windowEndsAt; }
    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }

    /**
     * Push the current feature's window out without touching anything else.
     *
     * Used when nobody new is eligible: the landing page only renders a
     * spotlight whose window is still open (so an abandoned row disappears
     * instead of claiming to be "featured this cycle" forever), but a quiet
     * 3h window shouldn't blank the section when the incumbent is still the
     * most active club there is. Re-upping the window keeps the section honest
     * and live while deliberately NOT re-running update()/notifyOwner(), so
     * the same owner isn't pushed "You're in the Spotlight!" every cycle.
     */
    public function extendWindow(\DateTimeImmutable $windowEndsAt): void
    {
        $this->windowEndsAt = $windowEndsAt;
    }

    /** True while this feature is still inside its advertised window. */
    public function isLive(?\DateTimeImmutable $now = null): bool
    {
        return $this->clubName !== '' && $this->windowEndsAt > ($now ?? new \DateTimeImmutable());
    }

    /**
     * @param array<int, array{opponent: string, scoreFor: int, scoreAgainst: int, week: int, result: string}> $recentFixtures
     * @param array<int, array{playerName: string, feePence: int, direction: string, counterpartyClub: string}> $recentTransfers
     * @param array<int, array{tier: int, titles: int, trophy: ?array, leagueName: ?string}> $trophies
     */
    public function update(
        string $clubName,
        ?string $clubId,
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
        array $trophies,
        ?\DateTimeImmutable $establishedAt,
        \DateTimeImmutable $windowStartsAt,
        \DateTimeImmutable $windowEndsAt,
        // Last and defaulted so the dozen callers that predate the Facilities panel
        // keep working: null is a real state here ("no payload"), not a missing
        // argument, and every call site uses named arguments anyway.
        ?array $facilities = null,
    ): void {
        $this->clubName                     = $clubName;
        $this->clubId                       = $clubId;
        $this->clubReputation               = $clubReputation;
        $this->dividendDrawsPence           = $dividendDrawsPence;
        $this->homeKitConfig                = $homeKitConfig;
        $this->awayKitConfig                = $awayKitConfig;
        $this->badgeConfig                  = $badgeConfig;
        $this->ownerName                    = $ownerName;
        $this->ownerNationality             = $ownerNationality;
        $this->ownerAppearance              = $ownerAppearance;
        $this->stadiumConfig                = $stadiumConfig;
        $this->facilities                   = $facilities;
        $this->recentFixtures               = $recentFixtures;
        $this->recentTransfers              = $recentTransfers;
        $this->topPerformerName             = $topPerformerName;
        $this->topPerformerGoals            = $topPerformerGoals;
        $this->topPerformerAssists          = $topPerformerAssists;
        $this->topPerformerAppearanceConfig = $topPerformerAppearanceConfig;
        $this->trophies                     = $trophies;
        $this->establishedAt                = $establishedAt;
        $this->windowStartsAt               = $windowStartsAt;
        $this->windowEndsAt                 = $windowEndsAt;
        $this->generatedAt                  = new \DateTimeImmutable();
    }
}
