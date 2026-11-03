<?php

declare(strict_types=1);

namespace App\Entity\Location;

use App\Entity\Concern\EditableJsonColumnTrait;
use App\Enum\Location\CountryProfileStatus;
use App\Enum\Location\DistanceUnit;
use App\Repository\Location\CountryProfileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-country data that has no home in App\Enum\Country, plus the readiness state of that
 * country's location data.
 *
 * ADDITIVE, NOT A REPLACEMENT. App\Enum\Country remains the canonical code list and the
 * type-hint across ~24 files, and its `value` is what club.country, league.country,
 * npc_club.country, season_snapshot.country and country_world_pack_cache.country all hold.
 * This row hangs editable data off that same code; a genuinely new country still needs an
 * enum case and a deploy. `name` and `defaultLocale` are MIRRORED from Country::label() and
 * Country::locale() by app:locations:seed, so an admin can correct a label without one.
 *
 * The primary key is the natural key `code`, not a surrogate int. That is what lets
 * location.country_code be a real FK without a join to resolve a code five existing columns
 * already store as a bare string. Precedent for a fixed/natural PK: StarterConfig::$id = 1.
 *
 * No FK is added to those five existing columns. They are nullable and device-mirrored, and
 * ClubInitializationService already validates them in application code by throwing
 * InvalidCountryCodeException — five migrations against live club data buy nothing.
 *
 * `bbox` and the two landPence bounds exist for EnrichmentValidator: an LLM asked for a
 * town's coordinates will occasionally answer with a point in the ocean or a valuation off by
 * three orders of magnitude, and a per-country box plus a per-country price band is what
 * turns "believable realism" into something checkable. Both are nullable; a country without
 * them simply gets the weaker global checks.
 */
#[ORM\Entity(repositoryClass: CountryProfileRepository::class)]
#[ORM\Table(name: 'country_profile')]
#[ORM\Index(columns: ['status'], name: 'idx_country_profile_status')]
#[ORM\HasLifecycleCallbacks]
class CountryProfile
{
    use EditableJsonColumnTrait;

    /** Equal to App\Enum\Country::$value. Immutable once created — disabled on the edit form. */
    #[ORM\Id]
    #[ORM\Column(length: 2)]
    private string $code;

    /** Mirrored from Country::label() at seed time, admin-correctable thereafter. */
    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $isoAlpha3 = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $continent = null;

    /** IANA zone, e.g. 'Europe/London'. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $defaultTimezone = null;

    /** ISO 4217, e.g. 'GBP'. */
    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currencyCode = null;

    /** What land_source quoted in, before conversion to pence. May differ from currencyCode. */
    #[ORM\Column(length: 3, nullable: true)]
    private ?string $landPriceCurrencyCode = null;

    /** Mirrored from Country::locale() at seed time. */
    #[ORM\Column(length: 10)]
    private string $defaultLocale = 'en_GB';

    #[ORM\Column(length: 4, enumType: DistanceUnit::class)]
    private DistanceUnit $distanceUnit = DistanceUnit::KM;

    #[ORM\Column(length: 16, enumType: CountryProfileStatus::class)]
    private CountryProfileStatus $status = CountryProfileStatus::DRAFT;

    /**
     * Coordinate bounding box for enrichment validation:
     * {"minLat": float, "maxLat": float, "minLng": float, "maxLng": float}.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $bbox = null;

    /** Lower bound for a plausible per-plot land valuation in this country, pence. */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $landPenceMin = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $landPenceMax = null;

    /** Stamped by CountryReadinessService once eight league tiers and ability ranges exist. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $tierStructureReadyAt = null;

    /** Stamped when status first reaches LIVE. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $locationsLiveAt = null;

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

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): void { $this->code = $code; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

    public function getIsoAlpha3(): ?string { return $this->isoAlpha3; }
    public function setIsoAlpha3(?string $isoAlpha3): void { $this->isoAlpha3 = $isoAlpha3; }

    public function getContinent(): ?string { return $this->continent; }
    public function setContinent(?string $continent): void { $this->continent = $continent; }

    public function getDefaultTimezone(): ?string { return $this->defaultTimezone; }
    public function setDefaultTimezone(?string $defaultTimezone): void { $this->defaultTimezone = $defaultTimezone; }

    public function getCurrencyCode(): ?string { return $this->currencyCode; }
    public function setCurrencyCode(?string $currencyCode): void { $this->currencyCode = $currencyCode; }

    public function getLandPriceCurrencyCode(): ?string { return $this->landPriceCurrencyCode; }
    public function setLandPriceCurrencyCode(?string $code): void { $this->landPriceCurrencyCode = $code; }

    public function getDefaultLocale(): string { return $this->defaultLocale; }
    public function setDefaultLocale(string $defaultLocale): void { $this->defaultLocale = $defaultLocale; }

    public function getDistanceUnit(): DistanceUnit { return $this->distanceUnit; }
    public function setDistanceUnit(DistanceUnit $distanceUnit): void { $this->distanceUnit = $distanceUnit; }

    public function getStatus(): CountryProfileStatus { return $this->status; }
    public function setStatus(CountryProfileStatus $status): void { $this->status = $status; }

    public function isLive(): bool { return $this->status === CountryProfileStatus::LIVE; }

    /** @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}|null */
    public function getBbox(): ?array { return $this->bbox; }
    public function setBbox(?array $bbox): void { $this->bbox = $bbox; }

    /**
     * Admin-facing virtual property: the bbox as editable JSON text.
     *
     * Via EditableJsonColumnTrait, so a stray comma leaves the stored box untouched and
     * reports itself against this field — rather than silently blanking the validation box
     * that stops AI coordinates landing in the wrong country.
     */
    public function getBboxJson(): string
    {
        return $this->invalidJsonInputFor('bboxJson')
            ?? ($this->bbox === null ? '' : (string) json_encode($this->bbox, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function setBboxJson(?string $json): void
    {
        $json = trim((string) $json);

        if ($json === '') {
            $this->bbox = null;

            return;
        }

        $decoded = $this->decodeJsonInput('bboxJson', $json);

        if ($decoded !== null) {
            $this->bbox = $decoded === [] ? null : $decoded;
        }
    }

    public function getLandPenceMin(): ?int { return $this->landPenceMin; }
    public function setLandPenceMin(?int $landPenceMin): void { $this->landPenceMin = $landPenceMin; }

    public function getLandPenceMax(): ?int { return $this->landPenceMax; }
    public function setLandPenceMax(?int $landPenceMax): void { $this->landPenceMax = $landPenceMax; }

    public function getTierStructureReadyAt(): ?\DateTimeImmutable { return $this->tierStructureReadyAt; }
    public function setTierStructureReadyAt(?\DateTimeImmutable $at): void { $this->tierStructureReadyAt = $at; }

    public function getLocationsLiveAt(): ?\DateTimeImmutable { return $this->locationsLiveAt; }
    public function setLocationsLiveAt(?\DateTimeImmutable $at): void { $this->locationsLiveAt = $at; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function __toString(): string { return "{$this->name} ({$this->code})"; }
}
