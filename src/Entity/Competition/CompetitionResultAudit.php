<?php

declare(strict_types=1);

namespace App\Entity\Competition;

use App\Repository\Competition\CompetitionResultAuditRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * The forensic half of a match result: the minute-by-minute event log, and the derivation
 * audit explaining how the scoreline was arrived at.
 *
 * Split off CompetitionResult because the two halves have opposite access levels and
 * opposite read frequencies, and the row could not express that. CompetitionResult's own
 * docblock already said the audit was "Admin-only — never sent in toClientSummary()", and it
 * was true of the event log too: between them these two columns have exactly one reader in
 * the repository, the admin competition detail page. Yet every public read of a bracket,
 * every GET /api/matches/{id} and every club-hub query decoded both, because ORM 3 removed
 * PARTIAL hydration and a mapped column is always hydrated.
 *
 * So the access boundary that previously existed only in a docblock is now in the schema.
 *
 * There is deliberately **no association to this from CompetitionResult**. A OneToOne
 * inverse side is always fetched eagerly regardless of `fetch: LAZY` — it cannot be proxied,
 * since the owning foreign key lives over here — which would quietly reintroduce the whole
 * cost. Fetch through CompetitionResultAuditRepository instead, and in bulk
 * (findByResults()) wherever more than one result is on screen: the admin detail page shows
 * a whole bracket's results at once, so reaching for these one at a time would just trade a
 * hydration cost for an N+1.
 *
 * These rows are **not** pruned when a competition completes, unlike entrant squads. A
 * squad is genuinely dead once no fixture can resolve; an audit's entire value is answering
 * "how was this result derived" about a match that has already been played, which is exactly
 * when someone asks. Ageing them out is a retention policy, not a cleanup.
 */
#[ORM\Entity(repositoryClass: CompetitionResultAuditRepository::class)]
#[ORM\Table(name: 'competition_result_audit')]
#[ORM\UniqueConstraint(name: 'uq_competition_result_audit_result', columns: ['result_id'])]
class CompetitionResultAudit
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\OneToOne(targetEntity: CompetitionResult::class)]
    #[ORM\JoinColumn(name: 'result_id', nullable: false, onDelete: 'CASCADE')]
    private CompetitionResult $result;

    /** @var list<array{minute: int, type: string, team?: string, scorer?: ?string, assist?: ?string, player?: ?string}> */
    #[ORM\Column(type: 'json')]
    private array $eventLogJson;

    /**
     * Engine-agnostic trail of how this result was derived: inputs, dominance breakdown,
     * tactical/manager/personality/cohesion multipliers, RNG seed, Poisson xG,
     * extra-time/penalty rolls, goal/assist/card weights and player rating maths — or
     * delegation and fallback notes for an AI engine.
     *
     * Nullable because an engine is not obliged to produce one, and rows generated before
     * the audit existed have none.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $computationAuditJson = null;

    /**
     * @param list<array<string, mixed>> $eventLogJson
     * @param array<string, mixed>|null $computationAuditJson
     */
    public function __construct(CompetitionResult $result, array $eventLogJson, ?array $computationAuditJson = null)
    {
        $this->id                   = new UuidV7();
        $this->result               = $result;
        $this->eventLogJson         = $eventLogJson;
        $this->computationAuditJson = $computationAuditJson;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getResult(): CompetitionResult { return $this->result; }

    /** @return list<array<string, mixed>> */
    public function getEventLogJson(): array { return $this->eventLogJson; }
    /** @param list<array<string, mixed>> $eventLogJson */
    public function setEventLogJson(array $eventLogJson): static { $this->eventLogJson = $eventLogJson; return $this; }

    /** @return array<string, mixed>|null */
    public function getComputationAuditJson(): ?array { return $this->computationAuditJson; }
    /** @param array<string, mixed>|null $computationAuditJson */
    public function setComputationAuditJson(?array $computationAuditJson): static { $this->computationAuditJson = $computationAuditJson; return $this; }

    /**
     * Read-only virtual accessor for the admin detail view — a CodeEditorField needs a
     * string, not the array-typed Doctrine column.
     */
    public function getComputationAuditJsonPretty(): string
    {
        return json_encode($this->computationAuditJson ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function getEventLogJsonPretty(): string
    {
        return json_encode($this->eventLogJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]';
    }
}
