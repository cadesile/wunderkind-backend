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

    /** @var list<array{minute: int, type: string, team?: string, scorer?: ?string, assist?: ?string, player?: ?string}> */
    #[ORM\Column(type: 'json')]
    private array $eventLogJson;

    /**
     * Snapshot of each side's club display data (name, kit colours, badge, stadium,
     * playing style, etc.) at the moment this result was generated — copied verbatim from
     * CompetitionEntrant::getSnapshotJson()['club']. Denormalized deliberately: a result is
     * a historical record (same reasoning as CompetitionFixture's SET NULL entrant FKs),
     * so it must not depend on the entrant/club rows still existing or being unchanged
     * later, and it's exactly the shape the client needs to render kits/names for this
     * match without a second lookup — see toClientSummary().
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
     * see DeterministicEngine::buildLineup(). Admin-only detail, not sent to clients.
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
        array $eventLogJson,
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
        $this->eventLogJson    = $eventLogJson;
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

    public function getEventLogJson(): array { return $this->eventLogJson; }

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

    /** Read-only virtual accessor for the admin detail view — see CompetitionEntrant::getSnapshotJsonPretty(). */
    public function getEventLogJsonPretty(): string
    {
        return json_encode($this->eventLogJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    public function getNarrativePayloadPretty(): ?string
    {
        return $this->narrativePayload === null
            ? null
            : (json_encode($this->narrativePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * The exact shape CompetitionController::show() sends to a real client for this
     * fixture's "result" field — single source of truth so the admin "what the device
     * receives" view and the public API can never drift apart (see the show() bug where
     * this was hardcoded to null and silently never matched what was actually stored).
     * homeClub/awayClub mirror exactly what's stored (see $homeClubJson's docblock) so the
     * client can render kits/badges/names without a second lookup. narrativePayload is the
     * full generated commentary timeline (see MatchNarrativeGeneratorService) — null for
     * results generated before this field existed, which the client should treat as an
     * absent/optional feature, not an error.
     *
     * wentToExtraTime/wentToPenalties/penaltyHomeScore/penaltyAwayScore record how a level
     * scoreline was actually settled — see this entity's field docblock. penaltyHomeScore/
     * penaltyAwayScore are null unless wentToPenalties is true.
     *
     * @return array{homeScore: int, awayScore: int, homeClub: array<string, mixed>, awayClub: array<string, mixed>, narrativePayload: ?array, wentToExtraTime: bool, wentToPenalties: bool, penaltyHomeScore: ?int, penaltyAwayScore: ?int}
     */
    public function toClientSummary(): array
    {
        return [
            'homeScore'        => $this->homeScore,
            'awayScore'        => $this->awayScore,
            'homeClub'         => $this->homeClubJson,
            'awayClub'         => $this->awayClubJson,
            'narrativePayload' => $this->narrativePayload,
            'wentToExtraTime'  => $this->wentToExtraTime,
            'wentToPenalties'  => $this->wentToPenalties,
            'penaltyHomeScore' => $this->penaltyHomeScore,
            'penaltyAwayScore' => $this->penaltyAwayScore,
        ];
    }

    /** Read-only virtual accessor for the admin detail view: exactly what toClientSummary() returns, pretty-printed. */
    public function getClientSummaryPretty(): string
    {
        return json_encode($this->toClientSummary(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** Read-only virtual accessor for the admin detail view: every stored field, raw, as JSON. */
    public function getFullPayloadPretty(): string
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
            'eventLogJson'     => $this->eventLogJson,
            'narrativePayload' => $this->narrativePayload,
            'wentToExtraTime'  => $this->wentToExtraTime,
            'wentToPenalties'  => $this->wentToPenalties,
            'penaltyHomeScore' => $this->penaltyHomeScore,
            'penaltyAwayScore' => $this->penaltyAwayScore,
            'engineIdentifier' => $this->engineIdentifier->value,
            'generatedAt'      => $this->generatedAt->format(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
