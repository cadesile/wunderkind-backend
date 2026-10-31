<?php

namespace App\Entity;

use App\Enum\LeaderboardCategory;
use App\Repository\LeaderboardEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: LeaderboardEntryRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_leaderboard_club_category_period', columns: ['club_id', 'category', 'period'])]
class LeaderboardEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(inversedBy: 'leaderboardEntries')]
    #[ORM\JoinColumn(nullable: false)]
    private Club $club;

    #[ORM\Column(enumType: LeaderboardCategory::class)]
    private LeaderboardCategory $category;

    /** Score value (earnings in pence, reputation points, HoF points) */
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true, 'default' => 0])]
    private int $score = 0;

    /**
     * Period identifier.
     * Use 'all-time' for lifetime boards, or a cadence key produced by
     * App\Service\Leaderboard\LeaderboardPeriod — '2026-W44' / '2026-M10' / '2026-Q4'.
     */
    #[ORM\Column(length: 20)]
    private string $period;

    /**
     * The cumulative value when this period first observed this club — what
     * observeCumulative() subtracts to turn a cumulative total into period growth.
     *
     * Null on 'all-time' rows, which are never growth-scored, and null on a period row
     * that has not been observed yet.
     *
     * The baseline lives HERE, on the live period row, rather than in a separate table or
     * on the archive. leaderboard_entry already holds exactly one row per
     * (club, category, period), so two columns add ZERO rows — the baseline is free. The
     * archive would be the wrong home: it is bounded to a display top-N, while a baseline
     * is needed for every eligible club.
     */
    #[ORM\Column(name: 'baseline_score', type: 'bigint', nullable: true, options: ['unsigned' => true])]
    private ?int $baselineScore = null;

    /**
     * The raw cumulative total at the last observation — the truthful value, before the
     * clamp in observeCumulative().
     *
     * Storing this separately is exactly what stops the clamp from corrupting the next
     * period's baseline: clamped to 0, baseline + score would no longer equal the truth,
     * and the club would be permanently suppressed.
     */
    #[ORM\Column(name: 'cumulative_score', type: 'bigint', nullable: true, options: ['unsigned' => true])]
    private ?int $cumulativeScore = null;

    /** Computed rank — null until the ranking job runs */
    #[ORM\Column(name: 'rank_position', type: 'integer', nullable: true, options: ['unsigned' => true])]
    private ?int $rank = null;

    /** Optional display string, e.g. the top scorer's name for golden_boot/playmaker. Null for other categories. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $displayLabel = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Club $club, LeaderboardCategory $category, string $period)
    {
        $this->id        = new UuidV7();
        $this->club   = $club;
        $this->category  = $category;
        $this->period    = $period;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getCategory(): LeaderboardCategory { return $this->category; }
    public function getCategoryValue(): string { return $this->category->value; }
    public function getPeriod(): string { return $this->period; }

    public function getScore(): int { return $this->score; }

    /**
     * Absolute scoring — 'all-time' rows only. A period row must go through
     * observeCumulative() instead, or it stores a standing where a growth belongs.
     */
    public function setScore(int $score): void
    {
        $this->score     = $score;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getBaselineScore(): ?int { return $this->baselineScore; }
    public function getCumulativeScore(): ?int { return $this->cumulativeScore; }
    public function hasBaseline(): bool { return $this->baselineScore !== null; }

    /**
     * Growth scoring — THE entire mechanism, and the one writer path for every period row.
     *
     * Score is NOT an accumulator anywhere in this system: every writer re-reads a
     * cumulative total and stores it absolutely (SyncService writes Club::getReputation(),
     * computeAggregateScores() writes career totals). So deleting rows at a period boundary
     * resets nothing — the next sync and the next five-minute cron tick put the identical
     * cumulative numbers straight back. The existing weekly board proves it: it gets a fresh period key
     * every ISO week and still shows all-time career goals.
     *
     * The only thing that resets a score is a baseline. Three numbers are kept, not one,
     * and only the CUMULATIVE chains: the next period subtracts a cumulative, never a
     * previous growth value, so growth can never compound its own clamping.
     *
     * max(0, …) is a product rule, not a safety net. score is bigint UNSIGNED, so a
     * negative is a database error — and growth genuinely goes negative, because
     * club_reputation decays daily (app:reputation:decay).
     *
     * The baseline is stamped lazily, at first observation INSIDE the period — so up to one
     * cron tick (5 min) or one sync of drift at a boundary, accepted as ~0.01% of a month.
     * What it buys is large: no pre-seeding pass, no baseline table, no seed archive, and no
     * "first period has no baseline" special case. It also closes the disable→re-enable
     * hazard structurally: while a definition is disabled no rows exist for its key, so on
     * re-enable growth is measured from the re-enable and the all-time leader cannot be
     * retro-awarded.
     */
    public function observeCumulative(int $cumulative): void
    {
        $this->baselineScore ??= $cumulative;
        $this->cumulativeScore = $cumulative;
        $this->score           = max(0, $cumulative - $this->baselineScore);
        $this->updatedAt       = new \DateTimeImmutable();
    }

    public function getRank(): ?int { return $this->rank; }
    public function setRank(?int $rank): void { $this->rank = $rank; }

    public function getDisplayLabel(): ?string { return $this->displayLabel; }
    public function setDisplayLabel(?string $displayLabel): void { $this->displayLabel = $displayLabel; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
