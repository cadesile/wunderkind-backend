<?php

declare(strict_types=1);

namespace App\Entity\Location;

use App\Entity\Club;
use App\Entity\User;
use App\Entity\UserLedger;
use App\Enum\Location\ClaimReleaseReason;
use App\Repository\Location\PlotClaimRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Ownership of one plot by one account, and the financial record of how it was bought.
 *
 * A SEPARATE TABLE rather than a `claimedBy` column on the plot, as the brief specified, and the
 * reason is worth keeping: a claim has a lifecycle and a price history, and a column has neither.
 * Keeping it here also leaves Location and LandPlot as pure geography, so a rival list is never a
 * function of who happens to be playing. The API still answers `claimedBy` — it reads active
 * claims at list time.
 *
 * THE HEADLINE CONSTRAINT is the partial unique on `plot_id WHERE released_at IS NULL`: one live
 * claim per plot, enforced by the database rather than by a check-then-insert that two concurrent
 * buyers would both pass.
 *
 * PRICES ARE COPIED IN, NOT REFERENCED. `landPencePaid` and `stadiumPencePaid` record what was
 * actually charged, because land gets revalued afterwards and a sell-back has to reason about
 * both the original figure and the current one. A join to the live valuation would silently
 * rewrite history every time an operator corrected a price.
 *
 * `user` IS THE OWNER, NOT `club`. `club` is nullable because a plot can be bought before a club
 * exists — the brief's own flow — and because a claim must outlive its club: the FK is ON DELETE
 * SET NULL so deleting a club cannot destroy the purchase record. `clubNameAtClaim` is frozen so
 * the API can still render `claimedBy` afterwards without joining a club that may be gone, the
 * same reasoning leaderboard_period_archive_entry uses for its frozen club identity.
 *
 * The three ledger FKs are SET NULL for the same reason in reverse: a ledger row is the money and
 * must never be deleted to tidy a claim, but a claim should survive one being archived.
 */
#[ORM\Entity(repositoryClass: PlotClaimRepository::class)]
#[ORM\Table(name: 'plot_claim')]
// One live claim per plot. Partial, because released claims accumulate as history and must be
// allowed to repeat. Predicate spelled exactly as pg_get_expr(indpred, indrelid) reports it.
#[ORM\UniqueConstraint(
    name: 'uq_plot_claim_active_plot',
    columns: ['plot_id'],
    options: ['where' => '(released_at IS NULL)'],
)]
#[ORM\Index(columns: ['location_id', 'released_at'], name: 'idx_plot_claim_location_released')]
#[ORM\Index(columns: ['club_id'], name: 'idx_plot_claim_club')]
#[ORM\Index(columns: ['user_id', 'claimed_at'], name: 'idx_plot_claim_user_claimed')]
class PlotClaim
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /** RESTRICT — a claim is a financial record and a plot must not be deleted out from under it. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private LandPlot $plot;

    /**
     * Denormalised, as the brief specified.
     *
     * Reachable through the plot, but carrying it here means "every claim in this country" and
     * "every claim in this region" are one join shorter — and those are admin screens that
     * otherwise walk plot -> location for every row.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Location $location;

    /** The owner. NOT NULL — a claim always belongs to an account, even before a club exists. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Nullable: bought before founding a club, and must outlive the club being deleted. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Club $club = null;

    /** Frozen when a club is attached, so `claimedBy` renders after the club is gone. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $clubNameAtClaim = null;

    #[ORM\Column]
    private \DateTimeImmutable $claimedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    #[ORM\Column(length: 24, nullable: true, enumType: ClaimReleaseReason::class)]
    private ?ClaimReleaseReason $releaseReason = null;

    /** What was actually charged for the land. Never recomputed. */
    #[ORM\Column(type: 'bigint')]
    private int $landPencePaid;

    /** What was actually charged for the ground. 0 until one is built. */
    #[ORM\Column(type: 'bigint')]
    private int $stadiumPencePaid = 0;

    /** The quote this purchase was made against, for forensics. SET NULL, never cascade. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?StadiumEstimate $stadiumEstimate = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UserLedger $landLedgerEntry = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UserLedger $stadiumLedgerEntry = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?UserLedger $sellBackLedgerEntry = null;

    /** What the player was paid on release, if anything. */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $sellBackPence = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        LandPlot $plot,
        User $user,
        int $landPencePaid,
        ?StadiumEstimate $stadiumEstimate = null,
        ?\DateTimeImmutable $claimedAt = null,
    ) {
        $now = $claimedAt ?? new \DateTimeImmutable();

        $this->id              = new UuidV7();
        $this->plot            = $plot;
        $this->location        = $plot->getLocation();
        $this->user            = $user;
        $this->landPencePaid   = $landPencePaid;
        $this->stadiumEstimate = $stadiumEstimate;
        $this->claimedAt       = $now;
        $this->createdAt       = $now;
        $this->updatedAt       = $now;
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getPlot(): LandPlot { return $this->plot; }
    public function getLocation(): Location { return $this->location; }
    public function getUser(): User { return $this->user; }

    public function getClub(): ?Club { return $this->club; }

    /** Attach a club, freezing its name for later display. */
    public function attachClub(Club $club): void
    {
        $this->club            = $club;
        $this->clubNameAtClaim = $club->getName();
        $this->updatedAt       = new \DateTimeImmutable();
    }

    public function getClubNameAtClaim(): ?string { return $this->clubNameAtClaim; }

    /**
     * What the API should show as `claimedBy`.
     *
     * The frozen name first: it is correct even after the club has been deleted, which is
     * precisely when a live club join would return nothing.
     */
    public function getClaimedByName(): ?string
    {
        return $this->clubNameAtClaim ?? $this->club?->getName();
    }

    public function getClaimedAt(): \DateTimeImmutable { return $this->claimedAt; }
    public function getReleasedAt(): ?\DateTimeImmutable { return $this->releasedAt; }
    public function getReleaseReason(): ?ClaimReleaseReason { return $this->releaseReason; }

    public function isActive(): bool { return $this->releasedAt === null; }

    public function getLandPencePaid(): int { return (int) $this->landPencePaid; }

    public function getStadiumPencePaid(): int { return (int) $this->stadiumPencePaid; }
    public function setStadiumPencePaid(int $pence): void
    {
        $this->stadiumPencePaid = $pence;
        $this->updatedAt        = new \DateTimeImmutable();
    }

    /** Everything the player has put into this plot. */
    public function getTotalPaidPence(): int
    {
        return $this->getLandPencePaid() + $this->getStadiumPencePaid();
    }

    public function hasStadium(): bool { return $this->getStadiumPencePaid() > 0; }

    public function getStadiumEstimate(): ?StadiumEstimate { return $this->stadiumEstimate; }

    public function getLandLedgerEntry(): ?UserLedger { return $this->landLedgerEntry; }
    public function setLandLedgerEntry(?UserLedger $entry): void { $this->landLedgerEntry = $entry; }

    public function getStadiumLedgerEntry(): ?UserLedger { return $this->stadiumLedgerEntry; }
    public function setStadiumLedgerEntry(?UserLedger $entry): void { $this->stadiumLedgerEntry = $entry; }

    public function getSellBackLedgerEntry(): ?UserLedger { return $this->sellBackLedgerEntry; }
    public function setSellBackLedgerEntry(?UserLedger $entry): void { $this->sellBackLedgerEntry = $entry; }

    public function getSellBackPence(): ?int
    {
        return $this->sellBackPence === null ? null : (int) $this->sellBackPence;
    }

    /**
     * Mark the claim ended.
     *
     * Constructor-plus-two-mutators, deliberately: a claim's identity and its prices are fixed at
     * purchase, and the only things that may change afterwards are the stadium being built and
     * the claim ending.
     */
    public function release(ClaimReleaseReason $reason, ?int $sellBackPence = null, ?\DateTimeImmutable $at = null): void
    {
        $this->releasedAt    = $at ?? new \DateTimeImmutable();
        $this->releaseReason = $reason;
        $this->sellBackPence = $sellBackPence;
        $this->updatedAt     = $this->releasedAt;
    }

    /** Undo a release — support only, and it can fail on the partial unique if the plot was retaken. */
    public function reinstate(): void
    {
        $this->releasedAt    = null;
        $this->releaseReason = null;
        $this->sellBackPence = null;
        $this->updatedAt     = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function __toString(): string
    {
        return sprintf('%s claimed by %s', (string) $this->plot, $this->getClaimedByName() ?? 'account');
    }
}
