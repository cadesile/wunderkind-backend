<?php

namespace App\Entity;

use App\Repository\SeasonRecordRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: SeasonRecordRepository::class)]
#[ORM\Table(name: 'season_record')]
class SeasonRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Academy $academy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private League $league;

    #[ORM\Column(type: 'smallint')]
    private int $season;

    #[ORM\Column(type: 'smallint')]
    private int $finalPosition;

    #[ORM\Column(type: 'smallint')]
    private int $gamesPlayed;

    #[ORM\Column(type: 'smallint')]
    private int $wins;

    #[ORM\Column(type: 'smallint')]
    private int $draws;

    #[ORM\Column(type: 'smallint')]
    private int $losses;

    #[ORM\Column(type: 'smallint')]
    private int $goalsFor;

    #[ORM\Column(type: 'smallint')]
    private int $goalsAgainst;

    #[ORM\Column(type: 'smallint')]
    private int $points;

    #[ORM\Column]
    private bool $promoted;

    #[ORM\Column]
    private bool $relegated;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Academy $academy,
        League $league,
        int $season,
        int $finalPosition,
        int $gamesPlayed,
        int $wins,
        int $draws,
        int $losses,
        int $goalsFor,
        int $goalsAgainst,
        int $points,
        bool $promoted,
        bool $relegated,
    ) {
        $this->id            = new UuidV7();
        $this->academy       = $academy;
        $this->league        = $league;
        $this->season        = $season;
        $this->finalPosition = $finalPosition;
        $this->gamesPlayed   = $gamesPlayed;
        $this->wins          = $wins;
        $this->draws         = $draws;
        $this->losses        = $losses;
        $this->goalsFor      = $goalsFor;
        $this->goalsAgainst  = $goalsAgainst;
        $this->points        = $points;
        $this->promoted      = $promoted;
        $this->relegated     = $relegated;
        $this->createdAt     = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getAcademy(): Academy { return $this->academy; }
    public function getLeague(): League { return $this->league; }
    public function getSeason(): int { return $this->season; }
    public function getFinalPosition(): int { return $this->finalPosition; }
    public function getGamesPlayed(): int { return $this->gamesPlayed; }
    public function getWins(): int { return $this->wins; }
    public function getDraws(): int { return $this->draws; }
    public function getLosses(): int { return $this->losses; }
    public function getGoalsFor(): int { return $this->goalsFor; }
    public function getGoalsAgainst(): int { return $this->goalsAgainst; }
    public function getPoints(): int { return $this->points; }
    public function isPromoted(): bool { return $this->promoted; }
    public function isRelegated(): bool { return $this->relegated; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
