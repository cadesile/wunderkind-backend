<?php

namespace App\Entity\Competition;

use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionDuration;
use App\Repository\Competition\ActiveCompetitionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * A concrete tournament run from a CompetitionTemplate. entrantCapacity/durationOption
 * are copied from the template at creation so a later template edit can't resize or
 * reshape a live instance.
 *
 * Registering -> Scheduled is triggered synchronously inside the registration
 * transaction that fills the last slot (see CompetitionRegistrationService), not by a
 * cron tick. A Postgres partial unique index (hand-added in the migration, since
 * Doctrine attributes can't express a WHERE clause) guarantees at most one REGISTERING
 * instance per template: uq_active_competition_one_open_per_template.
 */
#[ORM\Entity(repositoryClass: ActiveCompetitionRepository::class)]
#[ORM\Table(name: 'active_competition')]
#[ORM\Index(columns: ['template_id', 'status'], name: 'idx_active_competition_template_status')]
class ActiveCompetition
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(targetEntity: CompetitionTemplate::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CompetitionTemplate $template;

    #[ORM\Column(type: 'string', enumType: ActiveCompetitionStatus::class)]
    private ActiveCompetitionStatus $status;

    #[ORM\Column(type: 'smallint')]
    private int $entrantCapacity;

    #[ORM\Column(type: 'string', enumType: CompetitionDuration::class)]
    private CompetitionDuration $durationOption;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $registrationOpenedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cancellationReason = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(CompetitionTemplate $template)
    {
        $this->id                    = new UuidV7();
        $this->template               = $template;
        $this->status                 = ActiveCompetitionStatus::REGISTERING;
        $this->entrantCapacity        = $template->getEntrantCapacity();
        $this->durationOption         = $template->getDurationOption();
        $this->registrationOpenedAt   = new \DateTimeImmutable();
        $this->createdAt              = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getTemplate(): CompetitionTemplate { return $this->template; }

    public function getStatus(): ActiveCompetitionStatus { return $this->status; }
    public function setStatus(ActiveCompetitionStatus $status): static { $this->status = $status; return $this; }

    public function getEntrantCapacity(): int { return $this->entrantCapacity; }

    public function getDurationOption(): CompetitionDuration { return $this->durationOption; }

    public function getRegistrationOpenedAt(): \DateTimeImmutable { return $this->registrationOpenedAt; }

    public function getLockedAt(): ?\DateTimeImmutable { return $this->lockedAt; }
    public function setLockedAt(?\DateTimeImmutable $lockedAt): static { $this->lockedAt = $lockedAt; return $this; }

    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(?\DateTimeImmutable $startsAt): static { $this->startsAt = $startsAt; return $this; }

    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $endsAt): static { $this->endsAt = $endsAt; return $this; }

    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $completedAt): static { $this->completedAt = $completedAt; return $this; }

    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }
    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): static { $this->cancelledAt = $cancelledAt; return $this; }

    public function getCancellationReason(): ?string { return $this->cancellationReason; }
    public function setCancellationReason(?string $cancellationReason): static { $this->cancellationReason = $cancellationReason; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->template->getName(), $this->status->value);
    }
}
