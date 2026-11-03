<?php

declare(strict_types=1);

namespace App\Entity\Location;

use App\Repository\Location\StadiumEstimateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * A cached stadium-build price for one location.
 *
 * WHY IT IS A ROW AND NOT A CALCULATION. A hold freezes a quoted price for fifteen minutes, and
 * "the price cannot move while you decide" is only true if the quote is a THING the hold can
 * point at. Recomputing on read would let a land revaluation or a model change alter the figure
 * mid-hold, which is precisely the promise the hold exists to make.
 *
 * SUPERSEDED, NEVER DELETED. `plot_hold.stadium_estimate_id` is ON DELETE RESTRICT, so a live
 * hold physically prevents its estimate being removed — that FK is the enforcement mechanism for
 * the frozen quote, not decoration. Refreshing therefore stamps `supersededAt` on the old row
 * and inserts a new one; pruning superseded rows is a retention decision, and the FK will refuse
 * any attempt to prune one still in use.
 *
 * The partial unique keeps exactly one CURRENT estimate per (location, model version). It is
 * partial because superseded rows accumulate and must be allowed to repeat, and the predicate is
 * spelled exactly as pg_get_expr reports it so migrations:diff stays quiet.
 *
 * The inputs the figure was derived from live on StadiumEstimateInput — a separate row, because
 * ORM 3 decodes every mapped column on every read and that blob is read only by admin forensics
 * while this row is read on a hot path.
 */
#[ORM\Entity(repositoryClass: StadiumEstimateRepository::class)]
#[ORM\Table(name: 'stadium_estimate')]
#[ORM\UniqueConstraint(
    name: 'uq_stadium_estimate_current',
    columns: ['location_id', 'model_version'],
    options: ['where' => '(superseded_at IS NULL)'],
)]
#[ORM\Index(columns: ['location_id', 'created_at'], name: 'idx_stadium_estimate_location_created')]
class StadiumEstimate
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /** CASCADE — an estimate is derived data about one location and meaningless without it. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Location $location;

    #[ORM\Column(type: 'bigint')]
    private int $pence;

    /** Bumped whenever the formula changes, so an old quote is identifiable as old. */
    #[ORM\Column(length: 20)]
    private string $modelVersion;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Advisory freshness horizon. Null means "until something invalidates it". */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    /** Set when a newer estimate replaces this one. Never deleted — see the class docblock. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $supersededAt = null;

    public function __construct(
        Location $location,
        int $pence,
        string $modelVersion,
        ?\DateTimeImmutable $validUntil = null,
    ) {
        $this->id           = new UuidV7();
        $this->location     = $location;
        $this->pence        = $pence;
        $this->modelVersion = $modelVersion;
        $this->validUntil   = $validUntil;
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getLocation(): Location { return $this->location; }
    public function getPence(): int { return (int) $this->pence; }
    public function getModelVersion(): string { return $this->modelVersion; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getValidUntil(): ?\DateTimeImmutable { return $this->validUntil; }
    public function getSupersededAt(): ?\DateTimeImmutable { return $this->supersededAt; }

    public function isCurrent(): bool { return $this->supersededAt === null; }

    public function isStale(?\DateTimeImmutable $now = null): bool
    {
        if ($this->validUntil === null) {
            return false;
        }

        return ($now ?? new \DateTimeImmutable()) >= $this->validUntil;
    }

    /** Replaced by a newer estimate. The row stays; a live hold may still point at it. */
    public function supersede(?\DateTimeImmutable $at = null): void
    {
        $this->supersededAt ??= $at ?? new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s: %s (%s)', $this->location->getTown(), number_format($this->pence / 100, 2), $this->modelVersion);
    }
}
