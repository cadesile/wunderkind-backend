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
 * The (status, scheduledAt) index is the exact due-row query the round processor runs:
 * WHERE status IN (PENDING, SCHEDULED) AND scheduled_at <= NOW().
 */
#[ORM\Entity(repositoryClass: CompetitionRoundRepository::class)]
#[ORM\Table(name: 'competition_round')]
#[ORM\Index(columns: ['status', 'scheduled_at'], name: 'idx_competition_round_status_scheduled')]
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

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $scheduledAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: 'string', enumType: MatchEngineIdentifier::class, nullable: true)]
    private ?MatchEngineIdentifier $matchEngineIdentifier = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lockedForProcessingAt = null;

    public function __construct(ActiveCompetition $activeCompetition, int $roundIndex, string $label, \DateTimeImmutable $scheduledAt)
    {
        $this->id                = new UuidV7();
        $this->activeCompetition = $activeCompetition;
        $this->roundIndex        = $roundIndex;
        $this->label              = $label;
        $this->status              = CompetitionRoundStatus::PENDING;
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

    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $completedAt): static { $this->completedAt = $completedAt; return $this; }

    public function getMatchEngineIdentifier(): ?MatchEngineIdentifier { return $this->matchEngineIdentifier; }
    public function setMatchEngineIdentifier(?MatchEngineIdentifier $matchEngineIdentifier): static { $this->matchEngineIdentifier = $matchEngineIdentifier; return $this; }

    public function getLockedForProcessingAt(): ?\DateTimeImmutable { return $this->lockedForProcessingAt; }
    public function setLockedForProcessingAt(?\DateTimeImmutable $lockedForProcessingAt): static { $this->lockedForProcessingAt = $lockedForProcessingAt; return $this; }

    public function __toString(): string
    {
        return sprintf('%s — %s', $this->activeCompetition->getTemplate()->getName(), $this->label);
    }
}
