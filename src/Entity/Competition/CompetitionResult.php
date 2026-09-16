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
}
