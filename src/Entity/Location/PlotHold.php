<?php

declare(strict_types=1);

namespace App\Entity\Location;

use App\Entity\User;
use App\Repository\Location\PlotHoldRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * A fifteen-minute exclusive option on one plot, at a quoted price.
 *
 * It exists so a player can read the numbers and decide without the plot being sold from under
 * them, and without the price moving while they think. Both halves are enforced by the database
 * rather than by application code.
 *
 * TWO PARTIAL UNIQUES, and they settle the race rather than describe it. One active hold per
 * PLOT (two people tapping HOLD at the same instant) and one active hold per USER (nobody
 * reserves the whole map). PlotHoldService acquires through a raw INSERT ... ON CONFLICT DO
 * NOTHING and reads the affected row count, because a unique violation out of flush() closes the
 * shared EntityManager and this runs on a user-facing write.
 *
 * WHY THE PREDICATES CANNOT MENTION TIME. A unique index predicate must be IMMUTABLE, so
 * `WHERE expires_at > now()` is illegal in PostgreSQL. The predicates therefore key on
 * `released_at IS NULL AND consumed_at IS NULL`, which means an EXPIRED-but-unreleased hold still
 * occupies the index. The brief asked for "expired holds are ignored on read, no cleanup job
 * needed"; that is still delivered, but it needed a mechanism rather than just a predicate —
 * every read filters on `expires_at`, and the acquire path sweeps exactly the two rows that could
 * block it immediately before inserting. See PlotHoldService::acquire().
 *
 * `stadiumEstimate` is ON DELETE RESTRICT, and that FK is the actual enforcement of the frozen
 * quote: an estimate refresh physically cannot delete a row a live hold points at, so it
 * supersedes instead. `stadiumPence` is ALSO denormalised here, so the quote survives even the
 * estimate being superseded.
 */
#[ORM\Entity(repositoryClass: PlotHoldRepository::class)]
#[ORM\Table(name: 'plot_hold')]
#[ORM\UniqueConstraint(
    name: 'uq_plot_hold_active_plot',
    columns: ['plot_id'],
    options: ['where' => '((released_at IS NULL) AND (consumed_at IS NULL))'],
)]
#[ORM\UniqueConstraint(
    name: 'uq_plot_hold_active_user',
    columns: ['user_id'],
    options: ['where' => '((released_at IS NULL) AND (consumed_at IS NULL))'],
)]
#[ORM\Index(columns: ['expires_at'], name: 'idx_plot_hold_expires')]
#[ORM\Index(columns: ['stadium_estimate_id'], name: 'idx_plot_hold_estimate')]
class PlotHold
{
    /** How long a player has to decide. */
    public const HOLD_MINUTES = 15;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /** CASCADE — a hold is ephemeral and has nothing worth preserving past its plot. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LandPlot $plot;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The land price as quoted at the moment of the hold. */
    #[ORM\Column(type: 'bigint')]
    private int $landPence;

    /**
     * RESTRICT — this is what makes the frozen quote real.
     *
     * A refresh pass cannot delete an estimate a live hold references, so it supersedes the row
     * instead and the hold keeps pointing at the figure the player was shown.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private StadiumEstimate $stadiumEstimate;

    /** Denormalised from the estimate, so the quote survives supersession and pruning alike. */
    #[ORM\Column(type: 'bigint')]
    private int $stadiumPence;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /** Set when the hold becomes a claim. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    /** Set on an explicit cancel, or by the lazy sweep when an expired hold blocks an acquire. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    public function __construct(
        LandPlot $plot,
        User $user,
        int $landPence,
        StadiumEstimate $stadiumEstimate,
        ?\DateTimeImmutable $now = null,
    ) {
        $now = $now ?? new \DateTimeImmutable();

        $this->id              = new UuidV7();
        $this->plot            = $plot;
        $this->user            = $user;
        $this->landPence       = $landPence;
        $this->stadiumEstimate = $stadiumEstimate;
        $this->stadiumPence    = $stadiumEstimate->getPence();
        $this->createdAt       = $now;
        $this->expiresAt       = $now->modify('+' . self::HOLD_MINUTES . ' minutes');
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getPlot(): LandPlot { return $this->plot; }
    public function getUser(): User { return $this->user; }
    public function getLandPence(): int { return (int) $this->landPence; }
    public function getStadiumEstimate(): StadiumEstimate { return $this->stadiumEstimate; }
    public function getStadiumPence(): int { return (int) $this->stadiumPence; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getConsumedAt(): ?\DateTimeImmutable { return $this->consumedAt; }
    public function getReleasedAt(): ?\DateTimeImmutable { return $this->releasedAt; }

    /** The total the quote commits to — land plus ground. */
    public function getTotalPence(): int
    {
        return $this->getLandPence() + $this->getStadiumPence();
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return ($now ?? new \DateTimeImmutable()) >= $this->expiresAt;
    }

    /** Still usable: not cancelled, not already spent, not timed out. */
    public function isActive(?\DateTimeImmutable $now = null): bool
    {
        return $this->releasedAt === null
            && $this->consumedAt === null
            && !$this->isExpired($now);
    }

    public function secondsRemaining(?\DateTimeImmutable $now = null): int
    {
        $now = $now ?? new \DateTimeImmutable();

        return max(0, $this->expiresAt->getTimestamp() - $now->getTimestamp());
    }

    public function consume(?\DateTimeImmutable $at = null): void
    {
        $this->consumedAt ??= $at ?? new \DateTimeImmutable();
    }

    public function release(?\DateTimeImmutable $at = null): void
    {
        $this->releasedAt ??= $at ?? new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s held until %s', (string) $this->plot, $this->expiresAt->format('H:i:s'));
    }
}
