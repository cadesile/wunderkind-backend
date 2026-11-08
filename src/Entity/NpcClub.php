<?php

namespace App\Entity;

use App\Entity\Location\LandPlot;
use App\Entity\Location\Location;
use App\Enum\CitySize;
use App\Enum\Formation;
use App\Repository\NpcClubRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: NpcClubRepository::class)]
#[ORM\Table(name: 'npc_club')]
#[ORM\Index(name: 'idx_npc_club_land_plot', columns: ['land_plot_id'])]
/*
 * ONE CLUB PER PLOT, enforced here rather than by the code that assigns them. Partial, because
 * NULL is the normal state — most clubs are not seated — and the predicate is spelled exactly
 * as pg_get_expr(indpred, indrelid) reports it so SchemaIsInSyncTest does not propose
 * recreating it on every run. See that test's docblock on why partial uniques are the one
 * thing in this schema that silently drifts.
 */
#[ORM\UniqueConstraint(
    name: 'uniq_npc_club_land_plot',
    columns: ['land_plot_id'],
    options: ['where' => '(land_plot_id IS NOT NULL)'],
)]
class NpcClub
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(type: 'smallint')]
    private int $tier;

    #[ORM\Column(type: 'smallint')]
    private int $reputation;

    #[ORM\Column(length: 7)]
    private string $primaryColor;

    #[ORM\Column(length: 7)]
    private string $secondaryColor;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $abbreviation = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $stadiumName = null;

    #[ORM\Column(type: 'bigint')]
    private int $balance;

    #[ORM\Column(length: 20, options: ['default' => 'DIRECT'])]
    private string $playingStyle = 'DIRECT';

    #[ORM\Column(length: 20, options: ['default' => 'BALANCED'])]
    private string $financialApproach = 'BALANCED';

    #[ORM\Column(type: 'smallint', options: ['unsigned' => true, 'default' => 50])]
    private int $managerTemperament = 50;

    #[ORM\Column(type: 'json')]
    private array $facilities;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(enumType: CitySize::class, options: ['default' => 'MEDIUM'])]
    private CitySize $citySize = CitySize::MEDIUM;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $populationSize = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $isCapital = false;

    /**
     * The town this club plays in.
     *
     * NULLABLE AND STAYING SO. Three legitimate ways a club has no town: a country generated
     * before its locations were seeded (those draw from LocationCatalogueService::
     * PLACEHOLDER_PLACES, so the "town" is "Capital" or "Northern" and will never be a location
     * row), a club created by LeagueImportExportService::importClubs() from a hand-edited file,
     * and any club an admin has renamed freely.
     *
     * THE FOUR FIELDS ABOVE ARE NO LONGER INDEPENDENT. region, citySize, populationSize and
     * isCapital are DERIVED from this location — set from it at generation, and re-synced by
     * app:npc-clubs:link-locations. They stay as columns rather than becoming accessors because
     * they must survive the location being retired or the link being nulled, and because a
     * cached world pack reads them long after the fact. Treat them as a cache of the town's
     * facts, not as a second opinion about them.
     *
     * THE NAME IS NOT DERIVED. A club's name is its own from the moment it is generated, so
     * renaming a town never rewrites existing clubs — only newly generated ones use the new
     * name. Same principle as plot_claim.club_name_at_claim.
     *
     * SET NULL, not RESTRICT: nothing in this codebase deletes a Location (retirement is a
     * status flip — see Version20261103120000), so this only fires under admin surgery, where
     * losing the link beats blocking the operation.
     */
    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'location_id', nullable: true, onDelete: 'SET NULL')]
    private ?Location $location = null;

    /**
     * The land plot this club's ground stands on.
     *
     * NEW, AND IT MAKES AN OLD RULE REAL. LocationCapacityService has always subtracted NPC
     * clubs from a town's available plots — `min(npcClubs, NPC_PLOT_CAP)` — but it was a
     * COUNT, not a link: nothing recorded which plot, so a player could claim plot 1 while the
     * catalogue reported that plot as taken by a club. Seating the club on a row closes that.
     *
     * CAPPED BY GENERATION, NOT BY THIS COLUMN. Only the first NPC_PLOT_CAP clubs in a town are
     * seated; the rest keep a null plot and genuinely occupy nothing, which is the honest
     * reading of a ten-club town with one plot.
     *
     * NULLABLE FOR THE SAME REASONS $location IS, plus one more: a town with no plots yet —
     * imported as a bare name, never priced — has nothing to seat a club on. Those clubs get a
     * plot the next time app:npc-clubs:seat-plots runs after enrichment prices the town.
     *
     * SET NULL, not RESTRICT. LandPlotProvisioner::reapSurplus() deletes free plots when a town
     * is re-banded downward, and an NPC club must not be able to block that — losing the seat
     * is recoverable (re-run the backfill), a blocked reap is a town selling land its size no
     * longer justifies.
     */
    #[ORM\ManyToOne(targetEntity: LandPlot::class)]
    #[ORM\JoinColumn(name: 'land_plot_id', nullable: true, onDelete: 'SET NULL')]
    private ?LandPlot $landPlot = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?League $league = null;

    #[ORM\Column(enumType: Formation::class, options: ['default' => '4-4-2'])]
    private Formation $formation = Formation::F_442;

    /**
     * Kit + badge config (sprite KitConfig shape, 12 keys). Independent of
     * primaryColor/secondaryColor — see CLAUDE.md's NpcClub entry. Null until
     * generated/backfilled.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $identity = null;

    public function __construct(
        string $name,
        string $country,
        int $tier,
        int $reputation,
        string $primaryColor,
        string $secondaryColor,
        int $balance,
        array $facilities,
        ?string $region = null,
        CitySize $citySize = CitySize::MEDIUM,
        int $populationSize = 0,
        bool $isCapital = false,
        ?Location $location = null,
    ) {
        $this->id             = new UuidV7();
        $this->name           = $name;
        $this->country        = $country;
        $this->tier           = $tier;
        $this->reputation     = $reputation;
        $this->primaryColor   = $primaryColor;
        $this->secondaryColor = $secondaryColor;
        $this->balance        = $balance;
        $this->facilities     = $facilities;
        $this->region         = $region;
        $this->citySize       = $citySize;
        $this->populationSize = $populationSize;
        $this->isCapital      = $isCapital;
        $this->location       = $location;
        $this->createdAt      = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): static { $this->name = $v; return $this; }

    public function getCountry(): string { return $this->country; }
    public function setCountry(string $v): static { $this->country = $v; return $this; }

    public function getTier(): int { return $this->tier; }
    public function setTier(int $v): static { $this->tier = $v; return $this; }

    public function getReputation(): int { return $this->reputation; }
    public function setReputation(int $v): static { $this->reputation = $v; return $this; }

    public function getPrimaryColor(): string { return $this->primaryColor; }
    public function setPrimaryColor(string $v): static { $this->primaryColor = $v; return $this; }

    public function getSecondaryColor(): string { return $this->secondaryColor; }
    public function setSecondaryColor(string $v): static { $this->secondaryColor = $v; return $this; }

    public function getAbbreviation(): ?string { return $this->abbreviation; }
    public function setAbbreviation(?string $v): static { $this->abbreviation = $v; return $this; }

    public function getStadiumName(): ?string { return $this->stadiumName; }
    public function setStadiumName(?string $v): static { $this->stadiumName = $v; return $this; }

    public function getBalance(): int { return $this->balance; }
    public function setBalance(int $v): static { $this->balance = $v; return $this; }

    public function getPlayingStyle(): string { return $this->playingStyle; }
    public function setPlayingStyle(string $v): static { $this->playingStyle = $v; return $this; }

    public function getFinancialApproach(): string { return $this->financialApproach; }
    public function setFinancialApproach(string $v): static { $this->financialApproach = $v; return $this; }

    public function getManagerTemperament(): int { return $this->managerTemperament; }
    public function setManagerTemperament(int $v): static { $this->managerTemperament = max(0, min(100, $v)); return $this; }

    public function getFacilities(): array { return $this->facilities; }
    public function setFacilities(array $v): static { $this->facilities = $v; return $this; }

    public function getFacilitiesJson(): string
    {
        return json_encode($this->facilities, JSON_PRETTY_PRINT) ?: '{}';
    }

    public function setFacilitiesJson(string $v): static
    {
        $this->facilities = json_decode($v, true) ?? [];
        return $this;
    }

    public function getRegion(): ?string { return $this->region; }
    public function setRegion(?string $v): static { $this->region = $v; return $this; }

    public function getCitySize(): CitySize { return $this->citySize; }
    public function setCitySize(CitySize $v): static { $this->citySize = $v; return $this; }

    public function getPopulationSize(): int { return $this->populationSize; }
    public function setPopulationSize(int $v): static { $this->populationSize = $v; return $this; }

    public function getLocation(): ?Location { return $this->location; }
    public function setLocation(?Location $location): static { $this->location = $location; return $this; }

    public function getLandPlot(): ?LandPlot { return $this->landPlot; }
    public function setLandPlot(?LandPlot $landPlot): static { $this->landPlot = $landPlot; return $this; }

    public function isCapital(): bool { return $this->isCapital; }
    public function setIsCapital(bool $v): static { $this->isCapital = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getLeague(): ?League { return $this->league; }
    public function setLeague(?League $v): static { $this->league = $v; return $this; }

    public function getFormation(): Formation { return $this->formation; }
    public function setFormation(Formation $v): static { $this->formation = $v; return $this; }

    public function getIdentity(): ?array { return $this->identity; }

    /**
     * Setting a non-null identity with a home kit also syncs primaryColor/
     * secondaryColor from `identity['home']` — the home kit is now the single
     * source of truth for a club's colors, so there's no separate admin field
     * for them to drift out of sync with (see CLAUDE.md's "Kit & Badge Identity").
     */
    public function setIdentity(?array $v): static
    {
        $this->identity = $v;
        if ($v !== null && isset($v['home']['primary'], $v['home']['secondary'])) {
            $this->primaryColor   = $v['home']['primary'];
            $this->secondaryColor = $v['home']['secondary'];
        }
        return $this;
    }
}
