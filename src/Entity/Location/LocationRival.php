<?php

declare(strict_types=1);

namespace App\Entity\Location;

use App\Repository\Location\LocationRivalRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One of a location's four nearest rivals, computed from coordinates and stored.
 *
 * RIVALRY IS DIRECTIONAL AND THAT IS INTENDED. A small town's nearest rival is the big city
 * nearby; the city's own nearest four are other cities. So A may list B without B listing A,
 * and `rank` is only meaningful from the owning location's point of view. Storing unordered
 * pairs instead would make a rivalry mutual but let a big city accumulate unbounded rivals as
 * every surrounding town named it.
 *
 * A table rather than a json column on Location, for three reasons: UNIQUE(location, rank)
 * plus a CHECK on rank expresses "bounded at four" in the database, which a json array
 * cannot; a batch findByLocations() keeps the detail path at one extra query; and ORM 3
 * removed PARTIAL hydration, so every mapped column is decoded on every read of its row —
 * four rivals kept off `location` is four fewer things the bulk list read pays for.
 *
 * `id` is an int identity, not a uuid: this is derived data, rewritten wholesale by
 * app:locations:rivals, exactly like `translation`.
 *
 * THE TWO CHECK CONSTRAINTS LIVE IN RAW MIGRATION SQL ONLY — Doctrine has no CHECK mapping.
 * That is safe and causes no diff churn, because migrations:diff ignores CHECKs entirely
 * (unlike partial unique indexes, which must be declared on the entity). Do not "fix" their
 * absence here by dropping them from the migration.
 */
#[ORM\Entity(repositoryClass: LocationRivalRepository::class)]
#[ORM\Table(name: 'location_rival')]
#[ORM\UniqueConstraint(name: 'uq_location_rival_rank', columns: ['location_id', 'rank_position'])]
#[ORM\UniqueConstraint(name: 'uq_location_rival_pair', columns: ['location_id', 'rival_location_id'])]
// Reverse lookup ("which locations list me"), and FK-delete performance.
#[ORM\Index(columns: ['rival_location_id'], name: 'idx_location_rival_rival')]
class LocationRival
{
    /** Nearest-N. Enforced in the DB by ck_location_rival_rank. */
    public const MAX_RANK = 4;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** CASCADE — derived data with no meaning without its owner. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Location $location;

    /** CASCADE — the edge is meaningless without its target; the next pass refills the gap. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Location $rivalLocation;

    /** 1..MAX_RANK, nearest first. Column is `rank_position`; `rank` is reserved SQL. */
    #[ORM\Column(name: 'rank_position', type: 'smallint')]
    private int $rank;

    /** Kilometres always. Converted to miles at display time per CountryProfile::$distanceUnit. */
    #[ORM\Column(type: 'decimal', precision: 7, scale: 2)]
    private string $distanceKm;

    #[ORM\Column]
    private \DateTimeImmutable $computedAt;

    public function __construct(
        Location $location,
        Location $rivalLocation,
        int $rank,
        float $distanceKm,
        ?\DateTimeImmutable $computedAt = null,
    ) {
        $this->location       = $location;
        $this->rivalLocation  = $rivalLocation;
        $this->rank           = $rank;
        $this->distanceKm     = number_format($distanceKm, 2, '.', '');
        $this->computedAt     = $computedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getLocation(): Location { return $this->location; }
    public function getRivalLocation(): Location { return $this->rivalLocation; }
    public function getRank(): int { return $this->rank; }
    public function getDistanceKm(): float { return (float) $this->distanceKm; }
    public function getComputedAt(): \DateTimeImmutable { return $this->computedAt; }

    public function __toString(): string
    {
        return "#{$this->rank} {$this->rivalLocation->getTown()} ({$this->distanceKm} km)";
    }
}
