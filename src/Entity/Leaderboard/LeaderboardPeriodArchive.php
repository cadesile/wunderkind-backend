<?php

declare(strict_types=1);

namespace App\Entity\Leaderboard;

use App\Enum\Leaderboard\AwardCadence;
use App\Enum\Leaderboard\PeriodArchiveStatus;
use App\Enum\LeaderboardCategory;
use App\Repository\Leaderboard\LeaderboardPeriodArchiveRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Immutable header for one closed period — "October 2026 Golden Boot", archived forever.
 * The SeasonRecord / SeasonSnapshot shape.
 *
 * THIS ROW CARRIES THE CLAIM. uq_leaderboard_period_archive_category_period is what makes
 * closing a period idempotent: the close job INSERTs this row pending with
 * `ON CONFLICT DO NOTHING`, and whoever wins the insert owns the close. Both columns in
 * that unique are NOT NULL, so it is a plain unique rather than a partial one — which is
 * the whole reason `category` is denormalised here instead of keying on the nullable
 * definition FK. No `options: ['where' => …]`, so neither the pg_get_expr
 * predicate-string diff-churn trap nor the EntrantRewardClaim two-partial-index dance
 * applies.
 *
 * claimedAt is a LEASE, not a one-way flag — reclaimable after CLAIM_LEASE_SECONDS, the
 * same constant name and value as CompetitionRound so the two are recognisably one
 * pattern. A row left pending past its lease is the operational signal that something is
 * failing mid-close.
 *
 * Every identity field is constructor-only and there are no general setters: the three
 * mutators below are the only legal transitions.
 */
#[ORM\Entity(repositoryClass: LeaderboardPeriodArchiveRepository::class)]
#[ORM\Table(name: 'leaderboard_period_archive')]
#[ORM\UniqueConstraint(name: 'uq_leaderboard_period_archive_category_period', columns: ['category', 'period_key'])]
#[ORM\Index(columns: ['status', 'period_ends_at'], name: 'idx_lpa_status_period')]
#[ORM\Index(columns: ['cadence', 'closed_at'], name: 'idx_lpa_cadence_closed')]
class LeaderboardPeriodArchive
{
    /** Matches CompetitionRound::CLAIM_LEASE_SECONDS deliberately — one recognisable pattern. */
    public const CLAIM_LEASE_SECONDS = 300;

    /**
     * How many ranked rows are frozen, plus every awarded club regardless of rank (so an
     * award always has its own row to point at). Sub-top-100 history is not retained —
     * documented in docs/api/leaderboard.md.
     */
    public const ARCHIVED_ENTRY_LIMIT = 100;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /**
     * SET NULL, not CASCADE: the archive must outlive an admin deleting a definition — the
     * UserLedger::$sourceSyncRecord precedent. Admin links only; this is never identity,
     * which is why category/cadence/definitionName are denormalised below.
     */
    #[ORM\ManyToOne(targetEntity: LeaderboardAwardDefinition::class)]
    #[ORM\JoinColumn(name: 'definition_id', nullable: true, onDelete: 'SET NULL')]
    private ?LeaderboardAwardDefinition $definition = null;

    /** Denormalised so the archive is readable with the definition gone — and so the unique above can be plain. */
    #[ORM\Column(length: 40, enumType: LeaderboardCategory::class)]
    private LeaderboardCategory $category;

    /** Denormalised, same reason. */
    #[ORM\Column(length: 20, enumType: AwardCadence::class)]
    private AwardCadence $cadence;

    /** The admin label as it read at close time. */
    #[ORM\Column(name: 'definition_name', length: 100)]
    private string $definitionName;

    #[ORM\Column(name: 'period_key', length: 20)]
    private string $periodKey;

    /** Frozen instants — nothing downstream ever re-parses a period key. */
    #[ORM\Column(name: 'period_starts_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $periodStartsAt;

    /** Exclusive. */
    #[ORM\Column(name: 'period_ends_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $periodEndsAt;

    /** Which close last supplied the baselines this one consumed. Null for a definition's first close. */
    #[ORM\Column(name: 'baseline_period_key', length: 20, nullable: true)]
    private ?string $baselinePeriodKey = null;

    /** Eligible clubs on the board. INTEGER, not smallint — this count can exceed 32767. */
    #[ORM\Column(name: 'entry_count', type: 'integer', options: ['default' => 0])]
    private int $entryCount = 0;

    /** How many rows actually landed in the bounded entry table. */
    #[ORM\Column(name: 'archived_entry_count', type: 'smallint', options: ['default' => 0])]
    private int $archivedEntryCount = 0;

    #[ORM\Column(name: 'placements_awarded', type: 'smallint', options: ['default' => 0])]
    private int $placementsAwarded = 0;

    #[ORM\Column(length: 20, enumType: PeriodArchiveStatus::class)]
    private PeriodArchiveStatus $status = PeriodArchiveStatus::PENDING;

    /** The lease clock. */
    #[ORM\Column(name: 'claimed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $claimedAt;

    /** Set with `archived` / `skipped`, in the same flush. */
    #[ORM\Column(name: 'closed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(
        ?LeaderboardAwardDefinition $definition,
        LeaderboardCategory $category,
        AwardCadence $cadence,
        string $definitionName,
        string $periodKey,
        \DateTimeImmutable $periodStartsAt,
        \DateTimeImmutable $periodEndsAt,
        ?string $baselinePeriodKey,
        \DateTimeImmutable $claimedAt,
    ) {
        $this->id                = new UuidV7();
        $this->definition        = $definition;
        $this->category          = $category;
        $this->cadence           = $cadence;
        $this->definitionName    = $definitionName;
        $this->periodKey         = $periodKey;
        $this->periodStartsAt    = $periodStartsAt;
        $this->periodEndsAt      = $periodEndsAt;
        $this->baselinePeriodKey = $baselinePeriodKey;
        $this->claimedAt         = $claimedAt;
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getDefinition(): ?LeaderboardAwardDefinition { return $this->definition; }
    public function getCategory(): LeaderboardCategory { return $this->category; }
    public function getCadence(): AwardCadence { return $this->cadence; }
    public function getDefinitionName(): string { return $this->definitionName; }
    public function getPeriodKey(): string { return $this->periodKey; }
    public function getPeriodStartsAt(): \DateTimeImmutable { return $this->periodStartsAt; }
    public function getPeriodEndsAt(): \DateTimeImmutable { return $this->periodEndsAt; }
    public function getBaselinePeriodKey(): ?string { return $this->baselinePeriodKey; }
    public function getEntryCount(): int { return $this->entryCount; }
    public function getArchivedEntryCount(): int { return $this->archivedEntryCount; }
    public function getPlacementsAwarded(): int { return $this->placementsAwarded; }
    public function getStatus(): PeriodArchiveStatus { return $this->status; }
    public function getClaimedAt(): \DateTimeImmutable { return $this->claimedAt; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }

    public function markArchived(int $entryCount, int $archivedEntryCount, int $placementsAwarded, \DateTimeImmutable $at): static
    {
        $this->status             = PeriodArchiveStatus::ARCHIVED;
        $this->entryCount         = $entryCount;
        $this->archivedEntryCount = $archivedEntryCount;
        $this->placementsAwarded  = $placementsAwarded;
        $this->closedAt           = $at;

        return $this;
    }

    public function markSkipped(\DateTimeImmutable $at): static
    {
        $this->status   = PeriodArchiveStatus::SKIPPED;
        $this->closedAt = $at;

        return $this;
    }

    /** Takes over an expired lease. The caller has already won the conditional UPDATE. */
    public function reclaim(\DateTimeImmutable $at): static
    {
        $this->claimedAt = $at;

        return $this;
    }

    public function leaseHasExpired(\DateTimeImmutable $now): bool
    {
        return $this->claimedAt->getTimestamp() + self::CLAIM_LEASE_SECONDS <= $now->getTimestamp();
    }

    public function __toString(): string
    {
        return $this->definitionName . ' — ' . $this->periodKey;
    }
}
