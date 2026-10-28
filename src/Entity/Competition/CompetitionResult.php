<?php

namespace App\Entity\Competition;

use App\Enum\Competition\MatchEngineIdentifier;
use App\Repository\Competition\CompetitionResultRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: CompetitionResultRepository::class)]
#[ORM\Table(name: 'competition_result')]
#[ORM\Index(columns: ['fixture_id'], name: 'idx_competition_result_fixture')]
class CompetitionResult
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\OneToOne(targetEntity: CompetitionFixture::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private CompetitionFixture $fixture;

    #[ORM\Column(type: 'smallint')]
    private int $homeScore;

    #[ORM\Column(type: 'smallint')]
    private int $awayScore;

    /**
 * The client-facing half of a played fixture: scores, the frozen club copies, the lineups as
 * fielded, the commentary timeline and how a tie was settled.
 *
 * The forensic half — the minute-by-minute event log and the derivation audit — lives in
 * CompetitionResultAudit. Both had exactly one reader between them (the admin competition
 * detail page) while being decoded on every public bracket read, every GET
 * /api/matches/{id} and every club-hub query, because a mapped column is always hydrated.
 * There is no association to it from here on purpose; see that entity's docblock.
 *
     * Snapshot of each side's club display data (name, kit colours, badge, stadium,
     * playing style, etc.) at the moment this result was generated — copied verbatim from
     * CompetitionEntrant::getClubDisplayJson(). Denormalized deliberately: a result is
     * a historical record (same reasoning as CompetitionFixture's SET NULL entrant FKs),
     * so it must not depend on the entrant/club rows still existing or being unchanged
     * later, and it's exactly the shape the client needs to render kits/names for this
     * match without a second lookup.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $homeClubJson;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $awayClubJson;

    /**
     * The starting XI as fielded, each with this match's goals/assists/cards/rating —
     * see DeterministicEngine::buildLineup(). Served to clients by
     * MatchQueryService::serializeCupMatchDetail() behind GET /api/matches/{id} — no longer
     * embedded per fixture in the bracket response, which carried 63 of them at capacity 64.
     * Stored with id intact for the admin-only "full stored payload" view.
     *
     * @var list<array{id: ?string, name: ?string, position: ?string, goals: int, assists: int, yellowCards: int, redCards: int, rating: float}>
     */
    #[ORM\Column(type: 'json')]
    private array $homeLineupJson;

    /** @var list<array{id: ?string, name: ?string, position: ?string, goals: int, assists: int, yellowCards: int, redCards: int, rating: float}> */
    #[ORM\Column(type: 'json')]
    private array $awayLineupJson;

    /** @var array|null Full generated commentary timeline — see MatchNarrativeGeneratorService. Null for results generated before this feature existed. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $narrativePayload = null;

    /**
     * Every Competition fixture is a knockout-bracket match and can never end level — these
     * four fields record how a tie was actually settled. `homeScore`/`awayScore` above are
     * always the true final score (including extra time, if played); a shootout never
     * changes them. See DeterministicEngine's "Cup knockout resolution" docblock note.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $wentToExtraTime = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $wentToPenalties = false;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $penaltyHomeScore = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $penaltyAwayScore = null;

    #[ORM\Column(type: 'string', enumType: MatchEngineIdentifier::class)]
    private MatchEngineIdentifier $engineIdentifier;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $generatedAt;

    /**
     * @param array<string, mixed> $homeClubJson
     * @param array<string, mixed> $awayClubJson
     * @param list<array{id: ?string, name: ?string, position: ?string, goals: int, assists: int, yellowCards: int, redCards: int, rating: float}> $homeLineupJson
     * @param list<array{id: ?string, name: ?string, position: ?string, goals: int, assists: int, yellowCards: int, redCards: int, rating: float}> $awayLineupJson
     */
    public function __construct(
        CompetitionFixture $fixture,
        int $homeScore,
        int $awayScore,
        MatchEngineIdentifier $engineIdentifier,
        array $homeClubJson,
        array $awayClubJson,
        array $homeLineupJson,
        array $awayLineupJson,
    ) {
        $this->id              = new UuidV7();
        $this->fixture         = $fixture;
        $this->homeScore       = $homeScore;
        $this->awayScore       = $awayScore;
        $this->engineIdentifier = $engineIdentifier;
        $this->homeClubJson    = $homeClubJson;
        $this->awayClubJson    = $awayClubJson;
        $this->homeLineupJson  = $homeLineupJson;
        $this->awayLineupJson  = $awayLineupJson;
        $this->generatedAt     = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getFixture(): CompetitionFixture { return $this->fixture; }

    public function getHomeScore(): int { return $this->homeScore; }

    public function getAwayScore(): int { return $this->awayScore; }

    public function getHomeClubJson(): array { return $this->homeClubJson; }

    public function getAwayClubJson(): array { return $this->awayClubJson; }

    public function getHomeLineupJson(): array { return $this->homeLineupJson; }

    public function getAwayLineupJson(): array { return $this->awayLineupJson; }

    public function getNarrativePayload(): ?array { return $this->narrativePayload; }
    public function setNarrativePayload(?array $narrativePayload): static { $this->narrativePayload = $narrativePayload; return $this; }

    public function setShootoutInfo(bool $wentToExtraTime, bool $wentToPenalties, ?int $penaltyHomeScore, ?int $penaltyAwayScore): static
    {
        $this->wentToExtraTime  = $wentToExtraTime;
        $this->wentToPenalties  = $wentToPenalties;
        $this->penaltyHomeScore = $penaltyHomeScore;
        $this->penaltyAwayScore = $penaltyAwayScore;

        return $this;
    }

    public function isWentToExtraTime(): bool { return $this->wentToExtraTime; }
    public function isWentToPenalties(): bool { return $this->wentToPenalties; }
    public function getPenaltyHomeScore(): ?int { return $this->penaltyHomeScore; }
    public function getPenaltyAwayScore(): ?int { return $this->penaltyAwayScore; }

    public function getEngineIdentifier(): MatchEngineIdentifier { return $this->engineIdentifier; }

    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }

    public function getNarrativePayloadPretty(): ?string
    {
        return $this->narrativePayload === null
            ? null
            : (json_encode($this->narrativePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * Read-only view for the admin detail page: every stored field, raw, as JSON.
     *
     * Takes the audit rather than reaching for it, because this entity deliberately holds no
     * association to CompetitionResultAudit — see that class. The caller already has it
     * (batch-fetched for the whole bracket), so passing it in costs nothing and keeps the
     * forensic halves off this entity's own read path. A null audit means none was stored,
     * and the two keys render as empty rather than silently disappearing, so the view still
     * shows the shape of what a result holds.
     */
    public function getFullPayloadPretty(?CompetitionResultAudit $audit = null): string
    {
        return json_encode([
            'id'               => (string) $this->id,
            'fixtureId'        => (string) $this->fixture->getId(),
            'homeScore'        => $this->homeScore,
            'awayScore'        => $this->awayScore,
            'homeClub'         => $this->homeClubJson,
            'awayClub'         => $this->awayClubJson,
            'homeLineup'       => $this->homeLineupJson,
            'awayLineup'       => $this->awayLineupJson,
            'eventLogJson'     => $audit?->getEventLogJson() ?? [],
            'narrativePayload' => $this->narrativePayload,
            'wentToExtraTime'  => $this->wentToExtraTime,
            'wentToPenalties'  => $this->wentToPenalties,
            'penaltyHomeScore'     => $this->penaltyHomeScore,
            'penaltyAwayScore'     => $this->penaltyAwayScore,
            'engineIdentifier'     => $this->engineIdentifier->value,
            'computationAuditJson' => $audit?->getComputationAuditJson(),
            'generatedAt'          => $this->generatedAt->format(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
