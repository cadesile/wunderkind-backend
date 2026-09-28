<?php

namespace App\Entity\Competition;

use App\Entity\Club;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Repository\Competition\CompetitionEntrantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * A club's registration into an ActiveCompetition. snapshotJson is the client-supplied
 * JSON snapshot of the club (roster/staff/facilities) — validated structurally by
 * SnapshotValidator before persist, never server-built (there's no persisted Player/Staff
 * owned by a club server-side, per the Pool Lifecycle model).
 *
 * Any UPDATE to snapshotJson (resubmission) must go through raw SQL
 * (Connection::executeStatement), not setSnapshotJson()+flush() — the payload mixes
 * string/int values and Doctrine's json dirty-check silently skips such updates.
 */
#[ORM\Entity(repositoryClass: CompetitionEntrantRepository::class)]
#[ORM\Table(name: 'competition_entrant')]
#[ORM\Index(columns: ['active_competition_id', 'status'], name: 'idx_competition_entrant_competition_status')]
#[ORM\UniqueConstraint(name: 'uq_competition_entrant_club_competition', columns: ['active_competition_id', 'club_id'])]
class CompetitionEntrant
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(targetEntity: ActiveCompetition::class)]
    #[ORM\JoinColumn(name: 'active_competition_id', nullable: false, onDelete: 'CASCADE')]
    private ActiveCompetition $activeCompetition;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Club $club;

    #[ORM\Column(type: 'smallint')]
    private int $seed;

    #[ORM\Column(type: 'string', enumType: CompetitionEntrantStatus::class)]
    private CompetitionEntrantStatus $status;

    /** @var array<string, mixed> Client-supplied club/roster/staff/facilities snapshot. */
    #[ORM\Column(type: 'json')]
    private array $snapshotJson;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $snapshotLockedAt = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $snapshotVersion = 1;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $registeredAt;

    #[ORM\ManyToOne(targetEntity: CompetitionRound::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CompetitionRound $eliminatedInRound = null;

    public function __construct(ActiveCompetition $activeCompetition, Club $club, int $seed, array $snapshotJson)
    {
        $this->id                = new UuidV7();
        $this->activeCompetition = $activeCompetition;
        $this->club                = $club;
        $this->seed                 = $seed;
        $this->status                = CompetitionEntrantStatus::REGISTERED;
        $this->snapshotJson          = $snapshotJson;
        $this->registeredAt          = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getActiveCompetition(): ActiveCompetition { return $this->activeCompetition; }

    public function getClub(): Club { return $this->club; }

    public function getSeed(): int { return $this->seed; }
    public function setSeed(int $seed): static { $this->seed = $seed; return $this; }

    public function getStatus(): CompetitionEntrantStatus { return $this->status; }
    public function setStatus(CompetitionEntrantStatus $status): static { $this->status = $status; return $this; }

    public function getSnapshotJson(): array { return $this->snapshotJson; }

    /** Prefer raw SQL for updates outside initial persist — see class docblock. */
    public function setSnapshotJson(array $snapshotJson): static { $this->snapshotJson = $snapshotJson; return $this; }

    public function getSnapshotLockedAt(): ?\DateTimeImmutable { return $this->snapshotLockedAt; }
    public function setSnapshotLockedAt(?\DateTimeImmutable $snapshotLockedAt): static { $this->snapshotLockedAt = $snapshotLockedAt; return $this; }

    public function getSnapshotVersion(): int { return $this->snapshotVersion; }
    public function setSnapshotVersion(int $snapshotVersion): static { $this->snapshotVersion = $snapshotVersion; return $this; }

    public function getRegisteredAt(): \DateTimeImmutable { return $this->registeredAt; }

    public function getEliminatedInRound(): ?CompetitionRound { return $this->eliminatedInRound; }
    public function setEliminatedInRound(?CompetitionRound $eliminatedInRound): static { $this->eliminatedInRound = $eliminatedInRound; return $this; }

    /**
     * Read-only virtual accessor for the admin detail view — CodeEditorField needs a
     * string, not the array-typed Doctrine column (the same json-column-to-form gotcha
     * documented for GameEventTemplate's *Json properties, but no setter/trait needed
     * here since CompetitionEntrantCrudController never renders an edit form).
     */
    public function getSnapshotJsonPretty(): string
    {
        return json_encode($this->snapshotJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** Index-page convenience column — avoids opening the full snapshot just to sanity-check tactics. */
    public function getSnapshotFormation(): ?string
    {
        $value = $this->snapshotJson['club']['formation'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function getSnapshotPlayingStyle(): ?string
    {
        $value = $this->snapshotJson['club']['playingStyle'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function getSnapshotPlayerCount(): int
    {
        $players = $this->snapshotJson['players'] ?? [];

        return is_array($players) ? count($players) : 0;
    }
}
