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

    /**
     * Operator-set club-naming content, overriding CountryContentRegistry's PHP consts.
     *
     * THREE COLUMNS, NOT ONE BLOB, because every layer above them is already per category:
     * the readiness check reports per category, the validator accepts or rejects per category,
     * the form has a field per category, and reverting to the built-in list is per category. A
     * column each makes the tri-state a DATABASE fact — NULL is no override, non-empty is an
     * override, and `[]` is never stored — so no reader has to re-implement a key-presence
     * convention in which absent, empty and malformed all have to mean the same thing.
     *
     * NULL IS THE NORMAL STATE. Twenty-five countries are authored in PHP and keep their
     * consts; a row is written here only when an operator sets one. Read them through
     * CountryNamingContentProvider, never directly — the effective value is the override if
     * there is one and the registry const otherwise.
     *
     * DELIBERATELY ABSENT FROM CountryProfileCrudController. Three raw-JSON textareas whose
     * only check is "is it JSON" would bypass NamingContentValidator entirely — and one of
     * these lists is fed to sprintf(), where a stray percent sign is a fatal a long way from
     * the form that stored it. Two editors for one column is two validation stories; there is
     * one, on the locations dashboard.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $prestigeSuffixes = null;

    /** @var list<string>|null See $prestigeSuffixes. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $genericSuffixes = null;

    /** @var list<string>|null See $prestigeSuffixes. sprintf templates, one %s each. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $stadiumFormats = null;

    /**
     * Operator-set PERSON-name pools, overriding NamePoolRegistry's PHP consts.
     *
     * Same tri-state and the same reasoning as $prestigeSuffixes above, one level along: NULL
     * is no override and the shipped pool answers, a non-empty list wins outright, and `[]` is
     * never stored.
     *
     * TWO COLUMNS, because an operator can reasonably want one and not the other — a country
     * whose shipped surnames are fine but whose thirty first names repeat. Overriding either
     * leaves the other on the const.
     *
     * READ THEM THROUGH NamePoolProvider, never directly, and note it MEMOISES where
     * CountryNamingContentProvider deliberately does not: these are read on the hottest loop
     * in the application, roughly 116,000 times during a full pool warm, and
     * MarketPoolService clears the identity map every fifty players, so Doctrine's own cache
     * is not standing behind it.
     *
     * THE OVERRIDE REPLACES, IT DOES NOT MERGE. A union would mean an operator could never
     * remove a bad shipped name.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $firstNames = null;

    /** @var list<string>|null See $firstNames. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $lastNames = null;

    /** Lower bound for a plausible per-plot land valuation in this country, pence. */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $landPenceMin = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $landPenceMax = null;

    /** Stamped by CountryReadinessService once the league pyramid and ability ranges exist. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $tierStructureReadyAt = null;

    /**
     * How deep this country's league pyramid is meant to be, 1-8.
     *
     * The DECLARED INTENT, set when the structure is generated — not a count of the rows,
     * which LeagueRepository::findByCountry() already gives and which is briefly wrong
     * mid-generation. NPC quality is interpolated against this (a tier-3-of-3 club should
     * be a bottom-of-the-pyramid club, not a mid-table one), and a half-built country must
     * not have its clubs rescaled by however many tiers happen to exist at that moment.
     *
     * Defaults to 8 because every country predating this column genuinely has eight.
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 8])]
    private int $leagueTierCount = 8;

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

    /**
     * Placeholders for the two COMPUTED index columns on CountryProfileCrudController.
     *
     * THEY EXIST ONLY SO EASYADMIN CAN READ SOMETHING, and without them the admin renders a red
     * "Inaccessible" badge where the Readiness and Locations verdicts should be — which is
     * precisely the column an operator consults before setting a country Live.
     *
     * The mechanism, because it is not obvious from the CRUD controller: EasyAdmin's
     * CommonPreConfigurator calls PropertyAccessor::getValue() for every field BEFORE any
     * formatValue() callback runs. A property that does not exist throws, the field is marked
     * unreadable with a null value, and the inaccessible template is substituted — so a
     * formatValue() callback on a property-less virtual field is never reached. Returning an
     * empty string here makes the read succeed; the real text comes from the controller's
     * formatValue(), which needs CountryReadinessService and a Connection and therefore cannot
     * live on the entity.
     *
     * Do not "clean these up" as unused: nothing calls them in PHP, and that is the point.
     */
    public function getReadinessSummary(): string { return ''; }

    public function getLocationCount(): string { return ''; }
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

    /** @return list<string>|null */
    public function getPrestigeSuffixes(): ?array { return $this->prestigeSuffixes; }

    /** @return list<string>|null */
    public function getGenericSuffixes(): ?array { return $this->genericSuffixes; }

    /** @return list<string>|null */
    public function getStadiumFormats(): ?array { return $this->stadiumFormats; }

    /** @return list<string>|null */
    public function getFirstNames(): ?array { return $this->firstNames; }

    /** @return list<string>|null */
    public function getLastNames(): ?array { return $this->lastNames; }

    /** @param list<string>|null $values */
    public function setFirstNames(?array $values): void
    {
        $this->firstNames = self::listOrNull($values);
    }

    /** @param list<string>|null $values */
    public function setLastNames(?array $values): void
    {
        $this->lastNames = self::listOrNull($values);
    }

    /**
     * The setters coerce [] to null, and that is load-bearing rather than tidy.
     *
     * An empty override is indistinguishable from "authored as nothing", and it reaches code
     * that cannot survive it: NpcClubGenerationService::pickSuffixForCitySize() does
     * $pool[array_rand($pool)] with no guard, and array_rand([]) throws. Its callers currently
     * cover that with `?: ['FC']`, but a new write path must not depend on its caller's
     * fallback for a guarantee it can make itself.
     *
     * @param list<string>|null $values
     */
    public function setPrestigeSuffixes(?array $values): void
    {
        $this->prestigeSuffixes = self::listOrNull($values);
    }

    /** @param list<string>|null $values */
    public function setGenericSuffixes(?array $values): void
    {
        $this->genericSuffixes = self::listOrNull($values);
    }

    /** @param list<string>|null $values */
    public function setStadiumFormats(?array $values): void
    {
        $this->stadiumFormats = self::listOrNull($values);
    }

    /**
     * @param  list<string>|null $values
     * @return list<string>|null
     */
    private static function listOrNull(?array $values): ?array
    {
        return ($values === null || $values === []) ? null : array_values($values);
    }

    public function getLandPenceMin(): ?int { return $this->landPenceMin; }
    public function setLandPenceMin(?int $landPenceMin): void { $this->landPenceMin = $landPenceMin; }

    public function getLandPenceMax(): ?int { return $this->landPenceMax; }
    public function setLandPenceMax(?int $landPenceMax): void { $this->landPenceMax = $landPenceMax; }

    public function getTierStructureReadyAt(): ?\DateTimeImmutable { return $this->tierStructureReadyAt; }
    public function setTierStructureReadyAt(?\DateTimeImmutable $at): void { $this->tierStructureReadyAt = $at; }

    public function getLeagueTierCount(): int { return $this->leagueTierCount; }

    public function setLeagueTierCount(int $count): void { $this->leagueTierCount = max(1, min(8, $count)); }

    public function getLocationsLiveAt(): ?\DateTimeImmutable { return $this->locationsLiveAt; }
    public function setLocationsLiveAt(?\DateTimeImmutable $at): void { $this->locationsLiveAt = $at; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function __toString(): string { return "{$this->name} ({$this->code})"; }
}
