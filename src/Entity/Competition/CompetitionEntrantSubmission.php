<?php

namespace App\Entity\Competition;

use App\Repository\Competition\CompetitionEntrantSubmissionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * The registration envelope exactly as the client posted it — every key, including the ones
 * no backend code reads yet.
 *
 * This is the third read profile in the split, and the reason it is a row of its own rather
 * than a column on CompetitionEntrantSquad. The other two are hot: the entrant's
 * `clubDisplayJson` is decoded on every public bracket render, and the squad row is decoded
 * twice per fixture at resolve. This one is read by nobody on a schedule — the admin snapshot
 * viewer, the spoof clone, and whatever tournament feature wants a club's state at entry.
 * Parked beside the squad row instead, every round resolution would decode it for nothing.
 *
 * Why it exists at all: `clubDisplayBlock()` is a picked key set, and that is load-bearing
 * for what the bracket reads — but it meant everything outside those ~22 keys was decoded,
 * dropped, and gone. A real 2026 submission is ~1.4MB, of which ~99% is `club` history
 * (`cupTrophies`, `facilityUpgrades`, financials, season tickets, stadium) that the client
 * sends on every registration and the backend discarded. Storing it costs the hot paths
 * nothing and stops the data being unrecoverable later.
 *
 * **Deliberately never pruned.** CompetitionEntrantSquad is dropped when a competition
 * completes, because no fixture can resolve afterwards and the rows are dead. The opposite is
 * true here: a finished tournament is exactly when someone asks what a club looked like when
 * it entered, and the consumers this is being kept for are not written yet. Same reasoning as
 * `competition_result_audit`.
 *
 * Content is **untrusted and unvalidated beyond SnapshotValidator's structural pass** — it is
 * whatever the client sent. Treat it as data, never as a source of game truth; the engine's
 * inputs are on the squad row and the display block, both of which are normalised.
 */
#[ORM\Entity(repositoryClass: CompetitionEntrantSubmissionRepository::class)]
#[ORM\Table(name: 'competition_entrant_submission')]
// Named explicitly for the same reason as uq_competition_entrant_squad_entrant: writeSubmission()
// upserts with ON CONFLICT (entrant_id), which needs a constraint by this name to match against,
// and leaving Doctrine to generate one leaves migrations:diff proposing a rename forever.
#[ORM\UniqueConstraint(name: 'uq_competition_entrant_submission_entrant', columns: ['entrant_id'])]
class CompetitionEntrantSubmission
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /**
     * Owning side only. CompetitionEntrant holds no inverse property, for the reason spelled
     * out on CompetitionEntrantSquad: a `OneToOne` inverse cannot be proxied and so is fetched
     * eagerly whatever `fetch` says — which on this table, the largest of the three, would be
     * the worst possible regression.
     */
    #[ORM\OneToOne(targetEntity: CompetitionEntrant::class)]
    #[ORM\JoinColumn(name: 'entrant_id', nullable: false, onDelete: 'CASCADE')]
    private CompetitionEntrant $entrant;

    /**
     * The whole posted body, verbatim: `club`, `players`, `staff`, `facilities`,
     * `clientSnapshotAt`, and anything the client adds later without a contract change.
     *
     * `players`/`staff` are duplicated from the squad row on purpose. They are ~7KB against
     * ~1.4MB of club, and keeping the envelope whole is the point — a reconstruction stitched
     * from two tables is a thing that can drift, while a verbatim copy answers "what was
     * actually sent" with no assembly.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $envelopeJson;

    /** Server clock at submission. Distinct from the client's own `clientSnapshotAt` inside the envelope. */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $submittedAt;

    /** @param array<string, mixed> $envelopeJson */
    public function __construct(CompetitionEntrant $entrant, array $envelopeJson, ?\DateTimeImmutable $submittedAt = null)
    {
        $this->id           = new UuidV7();
        $this->entrant      = $entrant;
        $this->envelopeJson = $envelopeJson;
        $this->submittedAt  = $submittedAt ?? new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getEntrant(): CompetitionEntrant { return $this->entrant; }

    /** @return array<string, mixed> */
    public function getEnvelopeJson(): array { return $this->envelopeJson; }
    /** @param array<string, mixed> $envelopeJson */
    public function setEnvelopeJson(array $envelopeJson): static { $this->envelopeJson = $envelopeJson; return $this; }

    public function getSubmittedAt(): \DateTimeImmutable { return $this->submittedAt; }
    public function setSubmittedAt(\DateTimeImmutable $submittedAt): static { $this->submittedAt = $submittedAt; return $this; }

    /** Read-only virtual accessor for the admin detail view — CodeEditorField needs a string, not an array column. */
    public function getEnvelopeJsonPretty(): string
    {
        return json_encode($this->envelopeJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
