<?php

declare(strict_types=1);

namespace App\Entity\Location;

use App\Entity\Concern\EditableJsonColumnTrait;
use App\Enum\CitySize;
use App\Enum\Location\EnrichmentStatus;
use App\Enum\Location\LocationStatus;
use App\Repository\Location\LocationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One real-world town a club can be founded in. Geography only — who owns what lives on
 * PlotClaim, so this row stays pure and a rival list is never a function of who is playing.
 *
 * THE ID IS A UuidV7, DELIBERATELY NOT THE TOWN NAME OR A SLUG OF IT. Towns get renamed and
 * spellings get corrected, and a claim, a hold and a ledger entry all point here; a
 * name-derived key would make a typo fix a data migration. `slug` exists for admin and log
 * display, and nothing references it. This matches the dominant domain-row convention in
 * this schema (Club, League, InboxMessage, UserLedger, ActiveCompetition).
 *
 * `lat`, `lng` and `landPence` are NULLABLE, which is load-bearing. The seed supplies only
 * what the old PHP consts held — town, region, population, is_capital — and the rest arrives
 * later by AI enrichment, so "not known yet" is a common and legitimate state. Defaulting
 * coordinates to 0/0 instead would put every un-enriched town at the same point in the
 * Atlantic, and the rival pass would rank them as each other's nearest neighbours.
 *
 * `citySize` is PERSISTED rather than computed on read. The BIG/MEDIUM/SMALL split is a
 * property of the whole country's place set (top 20% / bottom 50%, with is_capital forced to
 * BIG — see NpcClubGenerationService::classifyPlaces()), so computing it per call would make
 * the same town classify differently before and after a new town is added, while
 * NpcClub::$citySize and $populationSize are already persisted and would then disagree.
 * LocationClassificationService recomputes and stores it per country whenever the set
 * changes.
 *
 * Enrichment columns: `enrichmentClaimedAt` is a LEASE, not a flag. Taking it is a
 * conditional UPDATE, and only the caller whose UPDATE affected a row dispatches the
 * enrichment message — that is what turns fifty simultaneous viewers of one un-enriched town
 * into one API call. CLAIM_LEASE_SECONDS matches CompetitionRound and
 * LeaderboardPeriodArchive in both name and value; a row stuck QUEUED past its lease is
 * reclaimable, and is the operational signal that a worker died mid-call.
 *
 * The three `is*Locked` flags are set when an admin edits that field by hand, and enrichment
 * skips a locked field. A human correction must never be undone by a later re-run.
 */
#[ORM\Entity(repositoryClass: LocationRepository::class)]
#[ORM\Table(name: 'location')]
#[ORM\UniqueConstraint(name: 'uq_location_country_slug', columns: ['country_code', 'slug'])]
// Serves the list endpoint's required country filter, its status default, the
// `maxLandPence` filter and the default land-price sort, all from one index.
#[ORM\Index(columns: ['country_code', 'status', 'land_pence'], name: 'idx_location_country_status_land')]
#[ORM\Index(columns: ['country_code', 'region'], name: 'idx_location_country_region')]
// NOT unique: two real towns in one country can share a name (different regions), and that
// collision is something the admin should see, not something the schema should forbid.
#[ORM\Index(columns: ['country_code', 'town_normalised'], name: 'idx_location_country_town_norm')]
#[ORM\Index(columns: ['country_code', 'city_size'], name: 'idx_location_country_city_size')]
// The no-PostGIS bounding-box prefilter for the rival pass. postgres:16-alpine in both
// compose.yaml and docker-compose.prod.yml, so there is no GiST index to reach for.
#[ORM\Index(columns: ['lat', 'lng'], name: 'idx_location_lat_lng')]
// Serves both the admin overview's per-status counts and "claim the next N pending".
#[ORM\Index(columns: ['enrichment_status', 'enrichment_claimed_at'], name: 'idx_location_enrichment')]
#[ORM\HasLifecycleCallbacks]
class Location
{
    use EditableJsonColumnTrait;

    /** Seconds an enrichment claim is held before another worker may reclaim it. */
    public const CLAIM_LEASE_SECONDS = 300;

    /** Attempts after which the lazy path stops re-queueing and an admin must retry by hand. */
    public const MAX_ENRICHMENT_ATTEMPTS = 3;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /**
     * RESTRICT, not CASCADE — a location carries claims and real money. Retire the country
     * instead; CountryProfileStatus::RETIRED exists for that.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'country_code', referencedColumnName: 'code', nullable: false, onDelete: 'RESTRICT')]
    private CountryProfile $country;

    /** Admin/log display only. Nothing references this. */
    #[ORM\Column(length: 80)]
    private string $slug;

    /** Canonical (English) name. Editable. */
    #[ORM\Column(length: 120)]
    private string $town;

    /** ClubNameNormalizer::normalize($town) — backs club-name uniqueness and admin search. */
    #[ORM\Column(length: 120)]
    private string $townNormalised;

    /**
     * {"de": "München", "fr": "Munich"} — the welcome screen offers seven languages.
     *
     * A json column rather than TranslationKey/Translation rows: 865 locations x 7 languages
     * is ~6,000 rows for one short string each, translation_key.$key is documented as a
     * human-readable label (a uuid there defeats it), TranslationRepository::findAllForKey()
     * has no batch-by-many-keys method so a 200-row page would need 200 queries, and
     * `?lang=de` wants the name inline. The cost is that town names are the one translatable
     * content not visible on the admin Translations screen. Revisit if this grows beyond
     * names.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $localisedNames = null;

    #[ORM\Column(length: 120)]
    private string $region = '';

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $lat = null;

    #[ORM\Column(type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $lng = null;

    #[ORM\Column]
    private bool $isCoordinatesLocked = false;

    #[ORM\Column(nullable: true)]
    private ?int $population = null;

    /** Provenance, e.g. 'CountryContentRegistry (seeded)' or '<provider>:<model>:<date>'. */
    #[ORM\Column(length: 120)]
    private string $populationSource = '';

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $populationAsOf = null;

    #[ORM\Column]
    private bool $isPopulationLocked = false;

    /** Price of one plot here, pence. Overridable per plot — see LandPlot::$landPenceOverride. */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $landPence = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $landValuedAt = null;

    /** Where the valuation came from. The app claims "priced from real local values". */
    #[ORM\Column(length: 160)]
    private string $landSource = '';

    #[ORM\Column]
    private bool $isLandLocked = false;

    #[ORM\Column]
    private bool $isCapital = false;

    /** Derived and stored by LocationClassificationService — see the class docblock. */
    #[ORM\Column(length: 10, enumType: CitySize::class)]
    private CitySize $citySize = CitySize::SMALL;

    #[ORM\Column(length: 10, enumType: LocationStatus::class)]
    private LocationStatus $status = LocationStatus::ACTIVE;

    /**
     * Nulled whenever this location's coordinates change AND whenever a neighbour inside the
     * search radius gains coordinates — a newly enriched town changes other towns' nearest
     * four, so the invalidation has to fan out. `app:locations:rivals --stale-only` picks
     * these up.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rivalsComputedAt = null;

    #[ORM\Column(length: 16, enumType: EnrichmentStatus::class)]
    private EnrichmentStatus $enrichmentStatus = EnrichmentStatus::PENDING;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $enrichmentClaimedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $enrichedAt = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $enrichmentProvider = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $enrichmentModel = null;

    #[ORM\Column(type: 'smallint')]
    private int $enrichmentAttempts = 0;

    /** Truncated to 255 by the caller — the DeletionRequest::$failureReason convention. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $enrichmentError = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        ?CountryProfile $country = null,
        string $town = '',
        string $slug = '',
        string $townNormalised = '',
    ) {
        // Nullable constructor args so EasyAdmin's "New" action can instantiate this with no
        // arguments — the same trick Translation documents.
        if ($country !== null) {
            $this->country = $country;
        }

        $this->id             = new UuidV7();
        $this->town           = $town;
        $this->slug           = $slug;
        $this->townNormalised = $townNormalised;
        $this->createdAt      = new \DateTimeImmutable();
        $this->updatedAt      = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getCountry(): CountryProfile { return $this->country; }
    public function setCountry(CountryProfile $country): void { $this->country = $country; }

    public function getCountryCode(): string { return $this->country->getCode(); }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): void { $this->slug = $slug; }

    public function getTown(): string { return $this->town; }
    public function setTown(string $town): void { $this->town = $town; }

    public function getTownNormalised(): string { return $this->townNormalised; }
    public function setTownNormalised(string $townNormalised): void { $this->townNormalised = $townNormalised; }

    /** @return array<string, string>|null */
    public function getLocalisedNames(): ?array { return $this->localisedNames; }
    public function setLocalisedNames(?array $localisedNames): void { $this->localisedNames = $localisedNames; }

    /** Admin-facing virtual property: the json column as editable text. */
    public function getLocalisedNamesJson(): string
    {
        return $this->invalidJsonInputFor('localisedNamesJson')
            ?? json_encode($this->localisedNames ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function setLocalisedNamesJson(string $json): void
    {
        $decoded = $this->decodeJsonInput('localisedNamesJson', $json);

        if ($decoded !== null) {
            $this->localisedNames = $decoded === [] ? null : $decoded;
        }
    }

    /** The town's name in one language, falling back to the canonical `town`. */
    public function displayName(?string $languageCode = null): string
    {
        if ($languageCode === null || $this->localisedNames === null) {
            return $this->town;
        }

        $value = $this->localisedNames[$languageCode] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : $this->town;
    }

    public function getRegion(): string { return $this->region; }
    public function setRegion(string $region): void { $this->region = $region; }

    public function getLat(): ?float { return $this->lat === null ? null : (float) $this->lat; }
    public function getLng(): ?float { return $this->lng === null ? null : (float) $this->lng; }

    public function hasCoordinates(): bool { return $this->lat !== null && $this->lng !== null; }

    public function setCoordinates(?float $lat, ?float $lng): void
    {
        $this->lat = $lat === null ? null : (string) $lat;
        $this->lng = $lng === null ? null : (string) $lng;
        // Any coordinate change invalidates this location's own rival ranking. The fan-out to
        // neighbours is a bulk UPDATE in LocationRivalService, not something an entity can do.
        $this->rivalsComputedAt = null;
    }

    /**
     * Single-axis setters, for the admin form only.
     *
     * setCoordinates() is the pair most callers want, because a half coordinate is not a
     * location — but EasyAdmin binds one form field to one property, so lat and lng each need
     * their own setter. Both route through the same rival invalidation, so editing one axis in
     * the admin cannot leave a stale rival set behind.
     */
    public function setLat(?float $lat): void
    {
        $this->setCoordinates($lat, $this->getLng());
    }

    public function setLng(?float $lng): void
    {
        $this->setCoordinates($this->getLat(), $lng);
    }

    public function isCoordinatesLocked(): bool { return $this->isCoordinatesLocked; }
    public function setIsCoordinatesLocked(bool $locked): void { $this->isCoordinatesLocked = $locked; }

    public function getPopulation(): ?int { return $this->population; }
    public function setPopulation(?int $population): void { $this->population = $population; }

    public function getPopulationSource(): string { return $this->populationSource; }
    public function setPopulationSource(string $source): void { $this->populationSource = $source; }

    public function getPopulationAsOf(): ?\DateTimeImmutable { return $this->populationAsOf; }
    public function setPopulationAsOf(?\DateTimeImmutable $asOf): void { $this->populationAsOf = $asOf; }

    public function isPopulationLocked(): bool { return $this->isPopulationLocked; }
    public function setIsPopulationLocked(bool $locked): void { $this->isPopulationLocked = $locked; }

    public function getLandPence(): ?int { return $this->landPence === null ? null : (int) $this->landPence; }
    public function setLandPence(?int $landPence): void { $this->landPence = $landPence; }

    public function getLandValuedAt(): ?\DateTimeImmutable { return $this->landValuedAt; }
    public function setLandValuedAt(?\DateTimeImmutable $at): void { $this->landValuedAt = $at; }

    public function getLandSource(): string { return $this->landSource; }
    public function setLandSource(string $landSource): void { $this->landSource = $landSource; }

    public function isLandLocked(): bool { return $this->isLandLocked; }
    public function setIsLandLocked(bool $locked): void { $this->isLandLocked = $locked; }

    public function isCapital(): bool { return $this->isCapital; }
    public function setIsCapital(bool $isCapital): void { $this->isCapital = $isCapital; }

    public function getCitySize(): CitySize { return $this->citySize; }
    public function setCitySize(CitySize $citySize): void { $this->citySize = $citySize; }

    public function getStatus(): LocationStatus { return $this->status; }
    public function setStatus(LocationStatus $status): void { $this->status = $status; }

    public function isActive(): bool { return $this->status === LocationStatus::ACTIVE; }

    public function getRivalsComputedAt(): ?\DateTimeImmutable { return $this->rivalsComputedAt; }
    public function setRivalsComputedAt(?\DateTimeImmutable $at): void { $this->rivalsComputedAt = $at; }

    public function getEnrichmentStatus(): EnrichmentStatus { return $this->enrichmentStatus; }
    public function setEnrichmentStatus(EnrichmentStatus $status): void { $this->enrichmentStatus = $status; }

    public function getEnrichmentClaimedAt(): ?\DateTimeImmutable { return $this->enrichmentClaimedAt; }
    public function setEnrichmentClaimedAt(?\DateTimeImmutable $at): void { $this->enrichmentClaimedAt = $at; }

    public function getEnrichedAt(): ?\DateTimeImmutable { return $this->enrichedAt; }
    public function setEnrichedAt(?\DateTimeImmutable $at): void { $this->enrichedAt = $at; }

    public function getEnrichmentProvider(): ?string { return $this->enrichmentProvider; }
    public function setEnrichmentProvider(?string $provider): void { $this->enrichmentProvider = $provider; }

    public function getEnrichmentModel(): ?string { return $this->enrichmentModel; }
    public function setEnrichmentModel(?string $model): void { $this->enrichmentModel = $model; }

    public function getEnrichmentAttempts(): int { return $this->enrichmentAttempts; }
    public function setEnrichmentAttempts(int $attempts): void { $this->enrichmentAttempts = $attempts; }

    public function getEnrichmentError(): ?string { return $this->enrichmentError; }
    public function setEnrichmentError(?string $error): void
    {
        $this->enrichmentError = $error === null ? null : mb_substr($error, 0, 255);
    }

    /** Whether the lazy read path may still queue this row. */
    public function isLazyEnrichable(): bool
    {
        return $this->enrichmentStatus === EnrichmentStatus::PENDING
            && $this->enrichmentAttempts < self::MAX_ENRICHMENT_ATTEMPTS;
    }

    /** Everything a plot needs before it can be sold. */
    public function isSellable(): bool
    {
        return $this->isActive() && $this->hasCoordinates() && $this->getLandPence() !== null;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function __toString(): string { return "{$this->town}, {$this->region}"; }
}
