<?php

namespace App\Entity\Competition;

use App\Enum\Competition\CompetitionRoundStatus;
use App\Enum\Competition\MatchEngineIdentifier;
use App\Repository\Competition\CompetitionRoundRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * matchEngineIdentifier is materialized from CompetitionTemplate::roundEngineConfig at
 * round-creation time (keyed by label - QF/SF/FINAL/etc), not re-read live, so a later
 * template edit can't retroactively change an already-scheduled round's engine.
 *
 * Draw and resolve are two independently-scheduled, independently-claimed passes over the
 * same row: (status, scheduledAt) is the draw-due query CompetitionDrawService runs
 * (WHERE status = DRAW_PENDING AND scheduled_at <= NOW()), (status, matchesResolveAt) is
 * the resolve-due query CompetitionResultsService runs (WHERE status = DRAWN AND
 * matches_resolve_at <= NOW()). Each pass has its own claim column (drawLockedAt/
 * resolveLockedAt) — a single shared claim column can't serve both without the second
 * claim being indistinguishable from "already drawn".
 */
#[ORM\Entity(repositoryClass: CompetitionRoundRepository::class)]
#[ORM\Table(name: 'competition_round')]
#[ORM\Index(columns: ['status', 'scheduled_at'], name: 'idx_competition_round_status_scheduled')]
#[ORM\Index(columns: ['status', 'matches_resolve_at'], name: 'idx_competition_round_status_resolve')]
#[ORM\Index(columns: ['active_competition_id', 'round_index'], name: 'idx_competition_round_competition_index')]
#[ORM\UniqueConstraint(name: 'uq_competition_round_competition_index', columns: ['active_competition_id', 'round_index'])]
class CompetitionRound
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(targetEntity: ActiveCompetition::class)]
    #[ORM\JoinColumn(name: 'active_competition_id', nullable: false, onDelete: 'CASCADE')]
    private ActiveCompetition $activeCompetition;

    #[ORM\Column(type: 'smallint')]
    private int $roundIndex;

    #[ORM\Column(length: 20)]
    private string $label;

    #[ORM\Column(type: 'string', enumType: CompetitionRoundStatus::class)]
    private CompetitionRoundStatus $status;

    /** This round's DRAW due-time. Round 1: fixed at lock time. Round N>1: (re)written by
     *  CompetitionResultsService the moment round N-1's results publish. */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $scheduledAt;

    /** When this round was actually drawn (fixtures published). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    /** This round's RESOLVE due-time, set the instant it's drawn. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $matchesResolveAt = null;

    /** When this round's results were actually published. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: 'string', enumType: MatchEngineIdentifier::class, nullable: true)]
    private ?MatchEngineIdentifier $matchEngineIdentifier = null;

    /** Claim marker for CompetitionDrawService::claimDraw() — see class docblock. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $drawLockedAt = null;

    /** Claim marker for CompetitionResultsService::claimResults() — see class docblock. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolveLockedAt = null;

    /**
     * Claim marker for CompetitionRoundReminderService — set the instant a "results
     * incoming" push is actually dispatched, so an overlapping cron tick can't send it
     * twice. Same atomic-UPDATE claim idiom as drawLockedAt/resolveLockedAt, just for a
     * different action.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $reminderSentAt = null;

    public function __construct(ActiveCompetition $activeCompetition, int $roundIndex, string $label, \DateTimeImmutable $scheduledAt)
    {
        $this->id                = new UuidV7();
        $this->activeCompetition = $activeCompetition;
        $this->roundIndex        = $roundIndex;
        $this->label              = $label;
        $this->status              = CompetitionRoundStatus::DRAW_PENDING;
        $this->scheduledAt         = $scheduledAt;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getActiveCompetition(): ActiveCompetition { return $this->activeCompetition; }

    public function getRoundIndex(): int { return $this->roundIndex; }

    public function getLabel(): string { return $this->label; }

    public function getStatus(): CompetitionRoundStatus { return $this->status; }
    public function setStatus(CompetitionRoundStatus $status): static { $this->status = $status; return $this; }

    public function getScheduledAt(): \DateTimeImmutable { return $this->scheduledAt; }
    public function setScheduledAt(\DateTimeImmutable $scheduledAt): static { $this->scheduledAt = $scheduledAt; return $this; }

    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }

    public function getMatchesResolveAt(): ?\DateTimeImmutable { return $this->matchesResolveAt; }
    public function setMatchesResolveAt(?\DateTimeImmutable $matchesResolveAt): static { $this->matchesResolveAt = $matchesResolveAt; return $this; }

    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $completedAt): static { $this->completedAt = $completedAt; return $this; }

    public function getMatchEngineIdentifier(): ?MatchEngineIdentifier { return $this->matchEngineIdentifier; }
    public function setMatchEngineIdentifier(?MatchEngineIdentifier $matchEngineIdentifier): static { $this->matchEngineIdentifier = $matchEngineIdentifier; return $this; }

    public function getDrawLockedAt(): ?\DateTimeImmutable { return $this->drawLockedAt; }
    public function setDrawLockedAt(?\DateTimeImmutable $drawLockedAt): static { $this->drawLockedAt = $drawLockedAt; return $this; }

    public function getResolveLockedAt(): ?\DateTimeImmutable { return $this->resolveLockedAt; }
    public function setResolveLockedAt(?\DateTimeImmutable $resolveLockedAt): static { $this->resolveLockedAt = $resolveLockedAt; return $this; }

    public function getReminderSentAt(): ?\DateTimeImmutable { return $this->reminderSentAt; }
    public function setReminderSentAt(?\DateTimeImmutable $reminderSentAt): static { $this->reminderSentAt = $reminderSentAt; return $this; }

    public function __toString(): string
    {
        return sprintf('%s — %s', $this->activeCompetition->getTemplate()->getName(), $this->label);
    }
}
