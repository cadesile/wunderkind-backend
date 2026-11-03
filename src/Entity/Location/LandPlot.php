<?php

declare(strict_types=1);

namespace App\Entity\Location;

use App\Enum\Location\LandPlotStatus;
use App\Repository\Location\LandPlotRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * A buyable plot of land within one location. Claims and holds point HERE, not at the
 * location.
 *
 * The level exists from day one on purpose. A big city plausibly supports several clubs while
 * a small town supports one, and retrofitting a plot level later would mean migrating every
 * claim and hold row plus every API path that names a location. Seeding creates exactly one
 * plot per location (plot_number = 1), so until an operator adds more the behaviour is
 * identical to one-club-per-town.
 *
 * `landPenceOverride` is null for the common case and inherits Location::$landPence, so
 * revaluing a town reprices all its plots in one edit. A plot with its own figure keeps it.
 */
#[ORM\Entity(repositoryClass: LandPlotRepository::class)]
#[ORM\Table(name: 'land_plot')]
#[ORM\UniqueConstraint(name: 'uq_land_plot_location_number', columns: ['location_id', 'plot_number'])]
#[ORM\Index(columns: ['location_id', 'status'], name: 'idx_land_plot_location_status')]
#[ORM\HasLifecycleCallbacks]
class LandPlot
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /** RESTRICT — a plot can carry an active claim and ledger history. Retire, don't delete. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Location $location;

    #[ORM\Column(type: 'smallint')]
    private int $plotNumber = 1;

    /** Optional human label, e.g. "Salford Quays". */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $label = null;

    /** Null inherits Location::$landPence. */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $landPenceOverride = null;

    #[ORM\Column(length: 10, enumType: LandPlotStatus::class)]
    private LandPlotStatus $status = LandPlotStatus::ACTIVE;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(?Location $location = null, int $plotNumber = 1)
    {
        // Nullable so EasyAdmin's "New" action can instantiate with no arguments.
        if ($location !== null) {
            $this->location = $location;
        }

        $this->id         = new UuidV7();
        $this->plotNumber = $plotNumber;
        $this->createdAt  = new \DateTimeImmutable();
        $this->updatedAt  = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getLocation(): Location { return $this->location; }
    public function setLocation(Location $location): void { $this->location = $location; }

    public function getPlotNumber(): int { return $this->plotNumber; }
    public function setPlotNumber(int $plotNumber): void { $this->plotNumber = $plotNumber; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $label): void { $this->label = $label; }

    public function getLandPenceOverride(): ?int
    {
        return $this->landPenceOverride === null ? null : (int) $this->landPenceOverride;
    }

    public function setLandPenceOverride(?int $landPenceOverride): void
    {
        $this->landPenceOverride = $landPenceOverride;
    }

    /** The price actually charged for this plot — its own figure, else the location's. */
    public function getEffectiveLandPence(): ?int
    {
        return $this->getLandPenceOverride() ?? $this->location->getLandPence();
    }

    public function getStatus(): LandPlotStatus { return $this->status; }
    public function setStatus(LandPlotStatus $status): void { $this->status = $status; }

    public function isActive(): bool { return $this->status === LandPlotStatus::ACTIVE; }

    /** Whether this plot is in a state where it could be held and bought at all. */
    public function isSellable(): bool
    {
        return $this->isActive()
            && $this->location->isSellable()
            && $this->getEffectiveLandPence() !== null;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function __toString(): string
    {
        $label = $this->label !== null ? " ({$this->label})" : '';

        return "{$this->location->getTown()} plot {$this->plotNumber}{$label}";
    }
}
