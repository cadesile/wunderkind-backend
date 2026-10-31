<?php

declare(strict_types=1);

namespace App\Entity\Leaderboard;

use App\Entity\Competition\RewardTemplate;
use App\Repository\Leaderboard\LeaderboardPeriodArchiveEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One frozen row of a closed board — and, when it placed, the award record itself.
 *
 * Folding the award into this row rather than giving it its own table is deliberate: a
 * separate leaderboard_award table would buy a UNIQUE(archive, place) idempotency key, but
 * the header's own UNIQUE(category, period_key) is already the claim gate, and the partial
 * uq_lpae_archive_place below gives the per-place guarantee anyway. Two tables describing
 * the same fact is worse.
 *
 * Club identity is denormalised with NO FK — the SeasonRatingsSnapshot / ClubSpotlight
 * precedent — because the archive must survive the club being deleted. That is also why
 * the kit and badge configs are frozen here: the landing page's rule that any club listed
 * anywhere shows at least its badge has to keep holding for a club that no longer exists.
 *
 * Constructor-only, getters only, plus one markAwarded() applied inside the close
 * transaction.
 */
#[ORM\Entity(repositoryClass: LeaderboardPeriodArchiveEntryRepository::class)]
#[ORM\Table(name: 'leaderboard_period_archive_entry')]
#[ORM\UniqueConstraint(name: 'uq_lpae_archive_club', columns: ['archive_id', 'club_id'])]
#[ORM\UniqueConstraint(name: 'uq_lpae_archive_rank', columns: ['archive_id', 'rank_position'])]
// Partial, because `place` is nullable and PostgreSQL treats NULLs as distinct in a plain
// unique index. The predicate is spelled exactly as pg_get_expr(indpred, indrelid) reports
// it, per CLAUDE.md, so doctrine:schema:create builds it and migrations:diff stops
// proposing to drop it. This is the one partial index in the feature.
#[ORM\UniqueConstraint(
    name: 'uq_lpae_archive_place',
    columns: ['archive_id', 'place'],
    options: ['where' => '(place IS NOT NULL)'],
)]
#[ORM\Index(columns: ['club_id'], name: 'idx_lpae_club')]
#[ORM\Index(columns: ['user_id'], name: 'idx_lpae_user')]
class LeaderboardPeriodArchiveEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /** CASCADE — this is a child of its header and has no meaning without it. */
    #[ORM\ManyToOne(targetEntity: LeaderboardPeriodArchive::class)]
    #[ORM\JoinColumn(name: 'archive_id', nullable: false, onDelete: 'CASCADE')]
    private LeaderboardPeriodArchive $archive;

    /** Column name `rank_position` — `rank` is reserved SQL, same reason LeaderboardEntry does it. */
    #[ORM\Column(name: 'rank_position', type: 'integer')]
    private int $rank;

    /** Denormalised, no FK — must survive club deletion. */
    #[ORM\Column(name: 'club_id', length: 36)]
    private string $clubId;

    #[ORM\Column(name: 'club_name', length: 100)]
    private string $clubName;

    /**
     * The credited account, denormalised and indexed so "awards won by this account" is one
     * query. Nulled (not deleted) on account deletion: the rank happened, the identity goes.
     */
    #[ORM\Column(name: 'user_id', length: 36, nullable: true)]
    private ?string $userId = null;

    /** The growth value as ranked. */
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true])]
    private int $score;

    /** The absolute cumulative at close. */
    #[ORM\Column(name: 'cumulative_score', type: 'bigint', options: ['unsigned' => true])]
    private int $cumulativeScore;

    /** What was subtracted. */
    #[ORM\Column(name: 'baseline_score', type: 'bigint', options: ['unsigned' => true, 'default' => 0])]
    private int $baselineScore = 0;

    /** Explains award (in)eligibility rather than leaving a reader to re-derive the rule. */
    #[ORM\Column(name: 'baseline_missing', options: ['default' => false])]
    private bool $baselineMissing = false;

    #[ORM\Column(name: 'award_eligible', options: ['default' => true])]
    private bool $awardEligible = true;

    /**
     * Frozen — names the player who scored IN THAT PERIOD for golden_boot / playmaker /
     * iron_man. The live row overwrites it next week, which is the whole point of freezing.
     */
    #[ORM\Column(name: 'display_label', length: 100, nullable: true)]
    private ?string $displayLabel = null;

    #[ORM\Column(name: 'home_kit_config', type: 'json', nullable: true)]
    private ?array $homeKitConfig = null;

    #[ORM\Column(name: 'away_kit_config', type: 'json', nullable: true)]
    private ?array $awayKitConfig = null;

    #[ORM\Column(name: 'badge_config', type: 'json', nullable: true)]
    private ?array $badgeConfig = null;

    /** Frozen, for the 2-letter chip — never an emoji flag. */
    #[ORM\Column(name: 'country_code', length: 2, nullable: true)]
    private ?string $countryCode = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $awarded = false;

    /** The placement place, when awarded. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $place = null;

    /** Frozen effects — the EntrantRewardClaim::$appliedEffectsJson precedent. */
    #[ORM\Column(name: 'applied_effects_json', type: 'json', nullable: true)]
    private ?array $appliedEffectsJson = null;

    #[ORM\ManyToOne(targetEntity: RewardTemplate::class)]
    #[ORM\JoinColumn(name: 'reward_template_id', nullable: true, onDelete: 'SET NULL')]
    private ?RewardTemplate $rewardTemplate = null;

    /**
     * A plain string, NOT a FK. Inbox messages cascade-delete with the club, and an award
     * must not vanish with its delivery receipt. Admin forensics only.
     */
    #[ORM\Column(name: 'inbox_message_id', length: 36, nullable: true)]
    private ?string $inboxMessageId = null;

    public function __construct(
        LeaderboardPeriodArchive $archive,
        int $rank,
        string $clubId,
        string $clubName,
        ?string $userId,
        int $score,
        int $cumulativeScore,
        int $baselineScore,
        bool $baselineMissing,
        bool $awardEligible,
        ?string $displayLabel = null,
        ?array $homeKitConfig = null,
        ?array $awayKitConfig = null,
        ?array $badgeConfig = null,
        ?string $countryCode = null,
    ) {
        $this->id              = new UuidV7();
        $this->archive         = $archive;
        $this->rank            = $rank;
        $this->clubId          = $clubId;
        $this->clubName        = $clubName;
        $this->userId          = $userId;
        $this->score           = $score;
        $this->cumulativeScore = $cumulativeScore;
        $this->baselineScore   = $baselineScore;
        $this->baselineMissing = $baselineMissing;
        $this->awardEligible   = $awardEligible;
        $this->displayLabel    = $displayLabel;
        $this->homeKitConfig   = $homeKitConfig;
        $this->awayKitConfig   = $awayKitConfig;
        $this->badgeConfig     = $badgeConfig;
        $this->countryCode     = $countryCode;
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getArchive(): LeaderboardPeriodArchive { return $this->archive; }
    public function getRank(): int { return $this->rank; }
    public function getClubId(): string { return $this->clubId; }
    public function getClubName(): string { return $this->clubName; }
    public function getUserId(): ?string { return $this->userId; }
    public function getScore(): int { return $this->score; }
    public function getCumulativeScore(): int { return $this->cumulativeScore; }
    public function getBaselineScore(): int { return $this->baselineScore; }
    public function isBaselineMissing(): bool { return $this->baselineMissing; }
    public function isAwardEligible(): bool { return $this->awardEligible; }
    public function getDisplayLabel(): ?string { return $this->displayLabel; }
    public function getHomeKitConfig(): ?array { return $this->homeKitConfig; }
    public function getAwayKitConfig(): ?array { return $this->awayKitConfig; }
    public function getBadgeConfig(): ?array { return $this->badgeConfig; }
    public function getCountryCode(): ?string { return $this->countryCode; }
    public function isAwarded(): bool { return $this->awarded; }
    public function getPlace(): ?int { return $this->place; }
    public function getAppliedEffectsJson(): ?array { return $this->appliedEffectsJson; }
    public function getRewardTemplate(): ?RewardTemplate { return $this->rewardTemplate; }
    public function getInboxMessageId(): ?string { return $this->inboxMessageId; }

    /** Applied inside the close transaction, in the same flush as the archive's own status. */
    public function markAwarded(int $place, array $appliedEffectsJson, ?RewardTemplate $rewardTemplate): static
    {
        $this->awarded            = true;
        $this->place              = $place;
        $this->appliedEffectsJson = $appliedEffectsJson;
        $this->rewardTemplate     = $rewardTemplate;

        return $this;
    }

    /** Written back in the same flush as the inbox message itself, so the link is navigable both ways. */
    public function linkInboxMessage(string $inboxMessageId): static
    {
        $this->inboxMessageId = $inboxMessageId;

        return $this;
    }

    /** Account deletion: the rank happened, the account identity goes. See AccountDeletionService. */
    public function forgetUser(): static
    {
        $this->userId = null;

        return $this;
    }

    public function __toString(): string
    {
        return '#' . $this->rank . ' ' . $this->clubName;
    }
}
