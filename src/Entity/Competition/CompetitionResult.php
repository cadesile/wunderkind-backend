<?php

namespace App\Entity\Competition;

use App\Enum\Competition\MatchEngineIdentifier;
use App\Repository\Competition\CompetitionResultRepository;
use App\Service\Competition\CompetitionRegistrationService;
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
     * CompetitionEntrant::getClubDisplayJson(). Denormalized deliberately: a result is
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
     * see DeterministicEngine::buildLineup(). Sent to clients via toClientSummary()
     * (id stripped there — see stripPlayerId()); stored here with id intact for the
     * admin-only "full stored payload" debug view.
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
     * Engine-agnostic audit trail detailing how this result was derived (inputs, dominance
     * breakdown, tactical/manager/personality/cohesion multipliers, RNG seed, Poisson xG,
     * extra-time/penalty shootout rolls, goal/assist/card weights, and player rating math,
     * or delegation/fallback notes for AI engines). Admin-only — never sent in toClientSummary().
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $computationAuditJson = null;

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

    /** @return array<string, mixed>|null */
    public function getComputationAuditJson(): ?array { return $this->computationAuditJson; }

    /** @param array<string, mixed>|null $computationAuditJson */
    public function setComputationAuditJson(?array $computationAuditJson): static
    {
        $this->computationAuditJson = $computationAuditJson;

        return $this;
    }

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

    /** Read-only virtual accessor for the admin detail view — see CompetitionEntrant::getClubDisplayJsonPretty(). */
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

    public function getComputationAuditPretty(): ?string
    {
        return $this->computationAuditJson === null
            ? null
            : (json_encode($this->computationAuditJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * The exact shape CompetitionController::show() sends to a real client for this
     * fixture's "result" field — single source of truth so the admin "what the device
     * receives" view and the public API can never drift apart (see the show() bug where
     * this was hardcoded to null and silently never matched what was actually stored).
     * homeClub/awayClub mirror what's stored (see $homeClubJson's docblock) minus `id` — see
     * stripClubId() — so the client can render kits/badges/names without a second lookup, and
     * without an unresolvable UUID sitting in the payload inviting a future maintainer to use
     * it as a matching key (a tournament opponent may not exist in any local store; the only
     * safe way to say "which club" in narrativePayload is `side`, not an id — see
     * MatchNarrativeGeneratorService and wunderkind-app's docs/api/tournament-match-result-payload.md,
     * which this shape is contracted against (this repo's own push-notification spec lives at
     * .context/stages/04_interfaces/output/push-notifications.md).
     * narrativePayload is the full generated commentary timeline — null for results generated
     * before this field existed, which the client should treat as an absent/optional feature,
     * not an error.
     *
     * wentToExtraTime/wentToPenalties/penaltyHomeScore/penaltyAwayScore record how a level
     * scoreline was actually settled — see this entity's field docblock. penaltyHomeScore/
     * penaltyAwayScore are null unless wentToPenalties is true.
     *
     * homeLineup/awayLineup are the starting XI as fielded, each with this match's
     * goals/assists/cards/rating — same id-stripping treatment as homeClub/awayClub (a
     * tournament opponent's players aren't in any local store either, so a player `id` is the
     * same dead weight an unresolvable club `id` would be).
     *
     * tournament/round/fixtureId make this payload self-contained: a result handed to
     * `MatchUX` on its own (not walked down from `GET /api/competitions/{id}`'s nested
     * rounds/fixtures tree) still knows what it's a result *of*. `tournament`/`round` mirror
     * CompetitionController::show()'s own top-level/round-level field names and shapes
     * exactly — same values, same keys, no separate vocabulary to keep in sync by hand.
     *
     * @return array{homeScore: int, awayScore: int, homeClub: array<string, mixed>, awayClub: array<string, mixed>, homeLineup: list<array<string, mixed>>, awayLineup: list<array<string, mixed>>, narrativePayload: ?array, wentToExtraTime: bool, wentToPenalties: bool, penaltyHomeScore: ?int, penaltyAwayScore: ?int, tournament: array<string, mixed>, round: array<string, mixed>, fixtureId: string}
     */
    public function toClientSummary(): array
    {
        $round             = $this->fixture->getRound();
        $activeCompetition = $round->getActiveCompetition();
        $homeEntrant       = $this->fixture->getHomeEntrant();
        $awayEntrant       = $this->fixture->getAwayEntrant();

        // The entrant's stored display block is read only as a base layer, exactly as its
        // snapshot's `club` object used to be, with this result's own frozen copy winning on
        // every key. That ordering is what lets a result outlive its entrant: when the
        // fixture's SET NULL entrant FK is already null, the frozen copy stands alone.
        $homeSnapshotClub = $homeEntrant?->getClubDisplayJson() ?? [];
        $awaySnapshotClub = $awayEntrant?->getClubDisplayJson() ?? [];

        $homeClub = $this->stripClubId(CompetitionRegistrationService::normalizeClubData(array_merge($homeSnapshotClub, $this->homeClubJson), $homeEntrant?->getClub()));
        $awayClub = $this->stripClubId(CompetitionRegistrationService::normalizeClubData(array_merge($awaySnapshotClub, $this->awayClubJson), $awayEntrant?->getClub()));

        return [
            'homeScore'        => $this->homeScore,
            'awayScore'        => $this->awayScore,
            'homeClub'         => $homeClub,
            'awayClub'         => $awayClub,
            // No snapshot backfill: the engine writes each player's `appearance` straight
            // into the lineup row (DeterministicEngine::calculatePerformance), so a stored
            // lineup is already complete. Reading the entrant's squad to re-fill a key that
            // is present is what made a bracket decode every squad it rendered.
            'homeLineup'       => CompetitionRegistrationService::formatLineupForClient($this->homeLineupJson),
            'awayLineup'       => CompetitionRegistrationService::formatLineupForClient($this->awayLineupJson),
            'narrativePayload' => $this->narrativePayload,
            'wentToExtraTime'  => $this->wentToExtraTime,
            'wentToPenalties'  => $this->wentToPenalties,
            'penaltyHomeScore' => $this->penaltyHomeScore,
            'penaltyAwayScore' => $this->penaltyAwayScore,
            'tournament'       => [
                'instanceId'   => (string) $activeCompetition->getId(),
                'templateName' => $activeCompetition->getTemplate()->getName(),
                'status'       => $activeCompetition->getStatus()->value,
                'startsAt'     => $activeCompetition->getStartsAt()?->format(DATE_ATOM),
                'endsAt'       => $activeCompetition->getEndsAt()?->format(DATE_ATOM),
            ],
            'round' => [
                'roundIndex'  => $round->getRoundIndex(),
                'label'       => $round->getLabel(),
                'status'      => $round->getStatus()->value,
                'scheduledAt' => $round->getScheduledAt()->format(DATE_ATOM),
            ],
            'fixtureId' => (string) $this->fixture->getId(),
        ];
    }

    /**
     * @param array<string, mixed> $player
     * @return array<string, mixed>
     */
    private function stripPlayerId(array $player): array
    {
        unset($player['id']);

        return $player;
    }

    /**
     * @param array<string, mixed> $club
     * @return array<string, mixed>
     */
    private function stripClubId(array $club): array
    {
        unset($club['id']);

        return $club;
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
            'penaltyHomeScore'     => $this->penaltyHomeScore,
            'penaltyAwayScore'     => $this->penaltyAwayScore,
            'engineIdentifier'     => $this->engineIdentifier->value,
            'computationAuditJson' => $this->computationAuditJson,
            'generatedAt'          => $this->generatedAt->format(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
