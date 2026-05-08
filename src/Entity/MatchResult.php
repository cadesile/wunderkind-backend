<?php

namespace App\Entity;

use App\Repository\MatchResultRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;


#[ORM\Entity(repositoryClass: MatchResultRepository::class)]
#[ORM\Table(name: 'match_result')]
class MatchResult
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Club $club;

    #[ORM\Column(type: 'smallint')]
    private int $goalsFor;

    #[ORM\Column(type: 'smallint')]
    private int $goalsAgainst;

    #[ORM\Column(type: 'smallint')]
    private int $week;

    #[ORM\Column(type: 'smallint')]
    private int $season;

    /** Client-side fixture UUID — idempotency key. Nullable for legacy v1 records. */
    #[ORM\Column(type: 'string', length: 255, nullable: true, unique: true)]
    private ?string $fixtureId = null;

    /** Display name of the opponent club (snapshot from client). */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $opponentClubName = null;

    /** True if the AMP club was the home side. */
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $isHome = null;

    /** Goals scored by the home team. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $homeGoals = null;

    /** Goals scored by the away team. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $awayGoals = null;

    /** League round number. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $round = null;

    /** Client-side ISO 8601 timestamp when the match was played. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $playedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Club $club,
        int $goalsFor,
        int $goalsAgainst,
        int $week,
        int $season,
    ) {
        $this->id           = new UuidV7();
        $this->club         = $club;
        $this->goalsFor     = $goalsFor;
        $this->goalsAgainst = $goalsAgainst;
        $this->week         = $week;
        $this->season       = $season;
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getGoalsFor(): int { return $this->goalsFor; }
    public function getGoalsAgainst(): int { return $this->goalsAgainst; }
    public function getWeek(): int { return $this->week; }
    public function getSeason(): int { return $this->season; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getFixtureId(): ?string { return $this->fixtureId; }
    public function setFixtureId(?string $v): static { $this->fixtureId = $v; return $this; }

    public function getOpponentClubName(): ?string { return $this->opponentClubName; }
    public function setOpponentClubName(?string $v): static { $this->opponentClubName = $v; return $this; }

    public function getIsHome(): ?bool { return $this->isHome; }
    public function setIsHome(?bool $v): static { $this->isHome = $v; return $this; }

    public function getHomeGoals(): ?int { return $this->homeGoals; }
    public function setHomeGoals(?int $v): static { $this->homeGoals = $v; return $this; }

    public function getAwayGoals(): ?int { return $this->awayGoals; }
    public function setAwayGoals(?int $v): static { $this->awayGoals = $v; return $this; }

    public function getRound(): ?int { return $this->round; }
    public function setRound(?int $v): static { $this->round = $v; return $this; }

    public function getPlayedAt(): ?\DateTimeImmutable { return $this->playedAt; }
    public function setPlayedAt(?\DateTimeImmutable $v): static { $this->playedAt = $v; return $this; }
}
