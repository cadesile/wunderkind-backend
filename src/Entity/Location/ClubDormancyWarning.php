<?php

declare(strict_types=1);

namespace App\Entity\Location;

use App\Entity\Club;
use App\Enum\Location\DormancyWarningStage;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * A record that a player was warned their plot was about to be reclaimed.
 *
 * EXISTS TO ANSWER A SUPPORT TICKET, not to drive the countdown. The countdown is computed from
 * the club's activity clock (PlotClaimRepository::findDormantClaims(), which defines it once as
 * COALESCE(last_synced_at, created_at)). These rows are the evidence that the player was told —
 * and reclaiming a plot that carries real money without that evidence is not defensible.
 *
 * DORMANCY_CYCLE_KEY IS WHAT MAKES THE DEDUPE RE-ARMABLE, and it is the whole reason this table
 * is not simply UNIQUE(claim, stage). A player can lapse, come back, and lapse again. Keyed on
 * (claim, stage) alone, the second lapse would find a row already present and silently send
 * nothing, for ever — the player would lose a plot having been warned only about a cycle they
 * had already recovered from. The key is the date of the activity timestamp the cycle was
 * computed from, so a new period of inactivity is a new cycle and gets its own warnings.
 *
 * ROW FIRST, THEN SEND, THEN STAMP — the AdminMessage::$pushSentAt idiom. Insert (which is what
 * claims the dedupe), send, then stamp sent_at. A crash between the send and the stamp risks one
 * duplicate warning on the next pass; the reverse order would risk a silent permanent skip,
 * which is far worse when the consequence is losing a plot.
 */
#[ORM\Entity(repositoryClass: \App\Repository\Location\ClubDormancyWarningRepository::class)]
#[ORM\Table(name: 'club_dormancy_warning')]
#[ORM\Index(columns: ['club_id'], name: 'idx_dormancy_warning_club')]
#[ORM\UniqueConstraint(
    name: 'uq_dormancy_warning_cycle',
    columns: ['claim_id', 'stage', 'dormancy_cycle_key'],
)]
class ClubDormancyWarning
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Club $club;

    #[ORM\ManyToOne(targetEntity: PlotClaim::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlotClaim $claim;

    #[ORM\Column(length: 16, enumType: DormancyWarningStage::class)]
    private DormancyWarningStage $stage;

    /**
     * Y-m-d of the activity timestamp this dormancy cycle was computed from.
     *
     * See the class docblock — this is the field that lets a returning-then-lapsing player be
     * warned again rather than silently skipped.
     */
    #[ORM\Column(length: 10)]
    private string $dormancyCycleKey;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    /**
     * The inbox message that carried the warning.
     *
     * A PLAIN STRING, NOT A FOREIGN KEY, and deliberately. Inbox messages cascade away with
     * their club, and a warning record must not vanish with its own delivery receipt — the
     * record is the evidence, the message is just how it was delivered. Same reasoning as
     * leaderboard_period_archive_entry.
     */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $inboxMessageId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Club $club,
        PlotClaim $claim,
        DormancyWarningStage $stage,
        string $dormancyCycleKey,
    ) {
        $this->id               = new UuidV7();
        $this->club             = $club;
        $this->claim            = $claim;
        $this->stage            = $stage;
        $this->dormancyCycleKey = $dormancyCycleKey;
        $this->createdAt        = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->stage->label(), $this->dormancyCycleKey);
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getClaim(): PlotClaim { return $this->claim; }
    public function getStage(): DormancyWarningStage { return $this->stage; }
    public function getDormancyCycleKey(): string { return $this->dormancyCycleKey; }

    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function isSent(): bool { return $this->sentAt !== null; }

    public function markSent(?string $inboxMessageId = null): void
    {
        $this->sentAt         = new \DateTimeImmutable();
        $this->inboxMessageId = $inboxMessageId;
    }

    public function getInboxMessageId(): ?string { return $this->inboxMessageId; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
