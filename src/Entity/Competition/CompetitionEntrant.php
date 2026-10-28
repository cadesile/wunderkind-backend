<?php

namespace App\Entity\Competition;

use App\Entity\Club;
use App\Enum\Competition\CompetitionEntrantStatus;
use App\Enum\Competition\EntrantSquadState;
use App\Repository\Competition\CompetitionEntrantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * A club's registration into an ActiveCompetition — the half of it that display paths read.
 *
 * This entity used to carry the whole client-supplied snapshot in one `snapshotJson`
 * column: club, the starting XI, staff and facilities together. That column had four
 * readers with four unrelated read profiles, and ORM 3 removed PARTIAL hydration, so every
 * reader decoded all of it. A single public bracket GET therefore decoded one whole squad
 * per entrant — up to 64 of them — which exhausted the request memory limit, and was the
 * same cause behind the admin entrant index timing out (see
 * CompetitionEntrantCrudController's docblock, which prescribed this split).
 *
 * What stays here is bounded and hot:
 *  - `clubDisplayJson` — the resolved, known-key display block
 *    (CompetitionRegistrationService::clubDisplayBlock()). Bounded on purpose: the
 *    normalizer it comes from passes unrecognised client keys through verbatim, so storing
 *    its raw output would let one registration inflate every later read.
 *  - `clientSnapshotAt`, `squadPlayerCount` — envelope facts the API and admin index want
 *    without touching a squad row.
 *
 * The starting XI, staff, and the engine-only club tuning keys live in
 * CompetitionEntrantSquad, which nothing on a client read path may load. There is
 * deliberately no association to it from here — a OneToOne inverse side is always fetched
 * eagerly and cannot be proxied, which would undo the split.
 *
 * Still validated structurally by SnapshotValidator before persist, and still never
 * server-built (no persisted Player/Staff is owned by a club, per the Pool Lifecycle model).
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

    /**
     * The resolved club display block — see CompetitionRegistrationService::clubDisplayBlock().
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $clubDisplayJson;

    /**
     * The client's own timestamp for the squad it submitted, stored verbatim as the string
     * it sent rather than parsed into a datetime: the API echoes this value back under
     * `viewer.snapshot.acceptedAt`, and round-tripping it through a DateTimeImmutable could
     * change the rendered offset. Length-capped because it is unvalidated client input, and
     * left null when absent or over-long — callers already fall back to `registeredAt`.
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $clientSnapshotAt = null;

    /**
     * How many players the submitted XI held. Denormalised so the admin index can show it
     * without loading a squad row — which is the whole point of the split.
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $squadPlayerCount = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $snapshotLockedAt = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $snapshotVersion = 1;

    /**
     * Whether this entrant's squad and submission rows have landed yet.
     *
     * Registration reserves the slot in a short locked transaction and queues the bulky
     * write, so an entrant exists for a moment with no squad. Defaults to STORED so the
     * synchronous path (and every row that predates the queue) is correct without a
     * backfill; the reserve path sets PENDING explicitly.
     *
     * Not part of `status` on purpose — see EntrantSquadState.
     */
    #[ORM\Column(type: 'string', length: 16, enumType: EntrantSquadState::class, options: ['default' => 'stored'])]
    private EntrantSquadState $squadState = EntrantSquadState::STORED;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $registeredAt;

    #[ORM\ManyToOne(targetEntity: CompetitionRound::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CompetitionRound $eliminatedInRound = null;

    /**
     * @param array<string, mixed> $clubDisplayJson Already passed through clubDisplayBlock().
     */
    public function __construct(
        ActiveCompetition $activeCompetition,
        Club $club,
        int $seed,
        array $clubDisplayJson,
        ?string $clientSnapshotAt = null,
        int $squadPlayerCount = 0,
    ) {
        $this->id                = new UuidV7();
        $this->activeCompetition = $activeCompetition;
        $this->club              = $club;
        $this->seed              = $seed;
        $this->status            = CompetitionEntrantStatus::REGISTERED;
        $this->clubDisplayJson   = $clubDisplayJson;
        $this->clientSnapshotAt  = self::sanitizeClientSnapshotAt($clientSnapshotAt);
        $this->squadPlayerCount  = $squadPlayerCount;
        $this->registeredAt      = new \DateTimeImmutable();
    }

    /** Null rather than a violation for anything unusable — this field must never fail a registration. */
    public static function sanitizeClientSnapshotAt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return ($trimmed !== '' && strlen($trimmed) <= 64) ? $trimmed : null;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getActiveCompetition(): ActiveCompetition { return $this->activeCompetition; }

    public function getClub(): Club { return $this->club; }

    public function getSeed(): int { return $this->seed; }
    public function setSeed(int $seed): static { $this->seed = $seed; return $this; }

    public function getStatus(): CompetitionEntrantStatus { return $this->status; }
    public function setStatus(CompetitionEntrantStatus $status): static { $this->status = $status; return $this; }

    /** @return array<string, mixed> */
    public function getClubDisplayJson(): array { return $this->clubDisplayJson; }
    /** @param array<string, mixed> $clubDisplayJson */
    public function setClubDisplayJson(array $clubDisplayJson): static { $this->clubDisplayJson = $clubDisplayJson; return $this; }

    public function getClientSnapshotAt(): ?string { return $this->clientSnapshotAt; }
    public function setClientSnapshotAt(?string $clientSnapshotAt): static { $this->clientSnapshotAt = self::sanitizeClientSnapshotAt($clientSnapshotAt); return $this; }

    public function getSquadPlayerCount(): int { return $this->squadPlayerCount; }
    public function setSquadPlayerCount(int $squadPlayerCount): static { $this->squadPlayerCount = $squadPlayerCount; return $this; }

    public function getSnapshotLockedAt(): ?\DateTimeImmutable { return $this->snapshotLockedAt; }
    public function setSnapshotLockedAt(?\DateTimeImmutable $snapshotLockedAt): static { $this->snapshotLockedAt = $snapshotLockedAt; return $this; }

    public function getSnapshotVersion(): int { return $this->snapshotVersion; }

    public function getSquadState(): EntrantSquadState { return $this->squadState; }
    public function setSquadState(EntrantSquadState $squadState): static { $this->squadState = $squadState; return $this; }
    /** True when the squad row the match engine reads is actually present. */
    public function hasStoredSquad(): bool { return $this->squadState === EntrantSquadState::STORED; }
    public function setSnapshotVersion(int $snapshotVersion): static { $this->snapshotVersion = $snapshotVersion; return $this; }

    public function getRegisteredAt(): \DateTimeImmutable { return $this->registeredAt; }

    public function getEliminatedInRound(): ?CompetitionRound { return $this->eliminatedInRound; }
    public function setEliminatedInRound(?CompetitionRound $eliminatedInRound): static { $this->eliminatedInRound = $eliminatedInRound; return $this; }

    /**
     * Read-only virtual accessor for the admin detail view — CodeEditorField needs a
     * string, not the array-typed Doctrine column (the same json-column-to-form gotcha
     * documented for GameEventTemplate's *Json properties, but no setter/trait needed
     * here since CompetitionEntrantCrudController never renders an edit form).
     *
     * This shows the display block only. The squad is a separate row with its own
     * accessor (CompetitionEntrantSquad::getSquadJsonPretty()) precisely so that opening
     * the index does not load it.
     */
    public function getClubDisplayJsonPretty(): string
    {
        return json_encode($this->clubDisplayJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** Index-page convenience column — now a plain read of the small display block. */
    public function getSnapshotFormation(): ?string
    {
        $value = $this->clubDisplayJson['formation'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function getSnapshotPlayingStyle(): ?string
    {
        $value = $this->clubDisplayJson['playingStyle'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Reads the denormalised counter rather than counting a players array, so the admin
     * index can render this column without touching competition_entrant_squad.
     */
    public function getSnapshotPlayerCount(): int
    {
        return $this->squadPlayerCount;
    }
}
