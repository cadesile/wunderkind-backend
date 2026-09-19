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

    /** @var list<array{minute: int, type: string, team?: string, scorer?: string}> */
    #[ORM\Column(type: 'json')]
    private array $eventLogJson;

    /** @var array|null Reserved for a future narrative match engine. Always null in Phase 1. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $narrativePayload = null;

    #[ORM\Column(type: 'string', enumType: MatchEngineIdentifier::class)]
    private MatchEngineIdentifier $engineIdentifier;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $generatedAt;

    public function __construct(CompetitionFixture $fixture, int $homeScore, int $awayScore, array $eventLogJson, MatchEngineIdentifier $engineIdentifier)
    {
        $this->id                = new UuidV7();
        $this->fixture             = $fixture;
        $this->homeScore            = $homeScore;
        $this->awayScore             = $awayScore;
        $this->eventLogJson           = $eventLogJson;
        $this->engineIdentifier        = $engineIdentifier;
        $this->generatedAt              = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getFixture(): CompetitionFixture { return $this->fixture; }

    public function getHomeScore(): int { return $this->homeScore; }

    public function getAwayScore(): int { return $this->awayScore; }

    public function getEventLogJson(): array { return $this->eventLogJson; }

    public function getNarrativePayload(): ?array { return $this->narrativePayload; }
    public function setNarrativePayload(?array $narrativePayload): static { $this->narrativePayload = $narrativePayload; return $this; }

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
     *
     * @return array{homeScore: int, awayScore: int}
     */
    public function toClientSummary(): array
    {
        return [
            'homeScore' => $this->homeScore,
            'awayScore' => $this->awayScore,
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
            'eventLogJson'     => $this->eventLogJson,
            'narrativePayload' => $this->narrativePayload,
            'engineIdentifier' => $this->engineIdentifier->value,
            'generatedAt'      => $this->generatedAt->format(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
