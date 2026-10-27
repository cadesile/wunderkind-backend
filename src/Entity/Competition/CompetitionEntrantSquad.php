<?php

namespace App\Entity\Competition;

use App\Repository\Competition\CompetitionEntrantSquadRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * The match-engine half of an entrant's registration snapshot: the starting XI and the
 * staff behind it. Split out of CompetitionEntrant::$snapshotJson deliberately.
 *
 * The blob it came from mixed four concerns with four unrelated read profiles — club
 * display data (read on every public bracket render, for every entrant), players (read
 * at resolve, plus an appearance backfill), staff (read at resolve only) and facilities
 * (never read at all) — in one column. ORM 3 removed PARTIAL hydration, so every reader
 * decoded all of it: 64 entrants x a whole squad on a single public GET, which is what
 * exhausted the request memory limit, and the same cause behind the admin entrant
 * index's 504 (see CompetitionEntrantCrudController's docblock, which prescribed exactly
 * this table).
 *
 * So the rule this table exists to enforce: **nothing on a client read path may load it.**
 * The bracket endpoint gets what it needs from CompetitionEntrant's own columns joined to
 * Club. The only legitimate readers are DeterministicEngine at resolve time, the
 * provisional-XI branch of a single match detail, and the admin snapshot viewer — all of
 * which want one or two rows, never the whole field.
 *
 * `facilities` is not carried over. Nothing in the repository ever read it, and
 * CompetitionSpoofEntrantService already wrote `[]` for it.
 */
#[ORM\Entity(repositoryClass: CompetitionEntrantSquadRepository::class)]
#[ORM\Table(name: 'competition_entrant_squad')]
// Named explicitly rather than left to `unique: true` on the join column below, which would
// make Doctrine want its own generated name (UNIQ_...) and leave migrations:diff proposing a
// rename forever. The name is load-bearing beyond tidiness: writeSquad() upserts with
// ON CONFLICT (entrant_id), which needs this constraint to exist to match against.
#[ORM\UniqueConstraint(name: 'uq_competition_entrant_squad_entrant', columns: ['entrant_id'])]
class CompetitionEntrantSquad
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /**
     * Owning side, so the entrant never has an association that could be eagerly walked
     * into. CompetitionEntrant deliberately holds no inverse property: a `OneToOne`
     * inverse side is **always** eagerly fetched by Doctrine regardless of `fetch: LAZY`
     * (it cannot be proxied, since the owning FK lives over here), which would quietly
     * reintroduce the exact cost this split removes.
     */
    #[ORM\OneToOne(targetEntity: CompetitionEntrant::class)]
    #[ORM\JoinColumn(name: 'entrant_id', nullable: false, onDelete: 'CASCADE')]
    private CompetitionEntrant $entrant;

    /** @var list<array<string, mixed>> The starting XI, exactly SnapshotValidator::REQUIRED_PLAYERS entries. */
    #[ORM\Column(type: 'json')]
    private array $playersJson;

    /** @var list<array<string, mixed>> Staff behind the XI; read only for manager ability/motivation. */
    #[ORM\Column(type: 'json')]
    private array $staffJson;

    /**
     * The club-level tuning inputs the match engine reads and nothing else does:
     * `cohesion`, `managerMotivation`, `lowUrgency`. They lived in the snapshot's `club`
     * object purely because normalizeClubData() merges unknown client keys through
     * verbatim, which made them indistinguishable from display fields.
     *
     * They are NOT display data, so they do not belong in
     * CompetitionEntrant::$clubDisplayJson, which every bracket render decodes.
     * `playingStyle` and `formation` deliberately stay over there instead of being
     * duplicated here: display needs them, the engine already holds the entrant, and a
     * second copy of a scalar is a source of truth that goes stale.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $clubEngineJson;

    /**
     * @param list<array<string, mixed>> $playersJson
     * @param list<array<string, mixed>> $staffJson
     * @param array<string, mixed> $clubEngineJson
     */
    public function __construct(CompetitionEntrant $entrant, array $playersJson, array $staffJson = [], array $clubEngineJson = [])
    {
        $this->id             = new UuidV7();
        $this->entrant        = $entrant;
        $this->playersJson    = $playersJson;
        $this->staffJson      = $staffJson;
        $this->clubEngineJson = $clubEngineJson;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getEntrant(): CompetitionEntrant { return $this->entrant; }

    /** @return list<array<string, mixed>> */
    public function getPlayersJson(): array { return $this->playersJson; }
    /** @param list<array<string, mixed>> $playersJson */
    public function setPlayersJson(array $playersJson): static { $this->playersJson = $playersJson; return $this; }

    /** @return list<array<string, mixed>> */
    public function getStaffJson(): array { return $this->staffJson; }
    /** @param list<array<string, mixed>> $staffJson */
    public function setStaffJson(array $staffJson): static { $this->staffJson = $staffJson; return $this; }

    /** @return array<string, mixed> */
    public function getClubEngineJson(): array { return $this->clubEngineJson; }
    /** @param array<string, mixed> $clubEngineJson */
    public function setClubEngineJson(array $clubEngineJson): static { $this->clubEngineJson = $clubEngineJson; return $this; }

    /**
     * Read-only virtual accessor for the admin detail view — CodeEditorField needs a
     * string, not the array-typed Doctrine column. Same gotcha as the accessor it
     * replaces on CompetitionEntrant.
     */
    public function getSquadJsonPretty(): string
    {
        return json_encode(
            ['club' => $this->clubEngineJson, 'players' => $this->playersJson, 'staff' => $this->staffJson],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        ) ?: '{}';
    }
}
