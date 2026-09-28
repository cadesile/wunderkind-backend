<?php

namespace App\Entity\Competition;

use App\Enum\Competition\CompetitionFixtureStatus;
use App\Repository\Competition\CompetitionFixtureRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * home/away/winner entrant FKs are SET NULL (not CASCADE) — a fixture is historical
 * record, like Transfer, and should outlive an admin-deleted entrant.
 */
#[ORM\Entity(repositoryClass: CompetitionFixtureRepository::class)]
#[ORM\Table(name: 'competition_fixture')]
#[ORM\Index(columns: ['round_id', 'slot_index'], name: 'idx_competition_fixture_round_slot')]
class CompetitionFixture
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(targetEntity: CompetitionRound::class)]
    #[ORM\JoinColumn(name: 'round_id', nullable: false, onDelete: 'CASCADE')]
    private CompetitionRound $round;

    #[ORM\Column(type: 'smallint')]
    private int $slotIndex;

    #[ORM\ManyToOne(targetEntity: CompetitionEntrant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CompetitionEntrant $homeEntrant = null;

    #[ORM\ManyToOne(targetEntity: CompetitionEntrant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CompetitionEntrant $awayEntrant = null;

    #[ORM\Column(type: 'string', enumType: CompetitionFixtureStatus::class)]
    private CompetitionFixtureStatus $status;

    #[ORM\ManyToOne(targetEntity: CompetitionEntrant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CompetitionEntrant $winnerEntrant = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    public function __construct(CompetitionRound $round, int $slotIndex, ?CompetitionEntrant $homeEntrant, ?CompetitionEntrant $awayEntrant)
    {
        $this->id           = new UuidV7();
        $this->round         = $round;
        $this->slotIndex      = $slotIndex;
        $this->homeEntrant     = $homeEntrant;
        $this->awayEntrant      = $awayEntrant;
        $this->status            = CompetitionFixtureStatus::PENDING;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getRound(): CompetitionRound { return $this->round; }

    public function getSlotIndex(): int { return $this->slotIndex; }

    public function getHomeEntrant(): ?CompetitionEntrant { return $this->homeEntrant; }
    public function setHomeEntrant(?CompetitionEntrant $homeEntrant): static { $this->homeEntrant = $homeEntrant; return $this; }

    public function getAwayEntrant(): ?CompetitionEntrant { return $this->awayEntrant; }
    public function setAwayEntrant(?CompetitionEntrant $awayEntrant): static { $this->awayEntrant = $awayEntrant; return $this; }

    public function getStatus(): CompetitionFixtureStatus { return $this->status; }
    public function setStatus(CompetitionFixtureStatus $status): static { $this->status = $status; return $this; }

    public function getWinnerEntrant(): ?CompetitionEntrant { return $this->winnerEntrant; }
    public function setWinnerEntrant(?CompetitionEntrant $winnerEntrant): static { $this->winnerEntrant = $winnerEntrant; return $this; }

    public function getProcessedAt(): ?\DateTimeImmutable { return $this->processedAt; }
    public function setProcessedAt(?\DateTimeImmutable $processedAt): static { $this->processedAt = $processedAt; return $this; }
}
