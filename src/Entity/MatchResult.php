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
    private Academy $academy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private NpcClub $opponentClub;

    #[ORM\Column(type: 'smallint')]
    private int $goalsFor;

    #[ORM\Column(type: 'smallint')]
    private int $goalsAgainst;

    #[ORM\Column(type: 'smallint')]
    private int $week;

    #[ORM\Column(type: 'smallint')]
    private int $season;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Academy $academy,
        NpcClub $opponentClub,
        int $goalsFor,
        int $goalsAgainst,
        int $week,
        int $season,
    ) {
        $this->id           = new UuidV7();
        $this->academy      = $academy;
        $this->opponentClub = $opponentClub;
        $this->goalsFor     = $goalsFor;
        $this->goalsAgainst = $goalsAgainst;
        $this->week         = $week;
        $this->season       = $season;
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getAcademy(): Academy { return $this->academy; }
    public function getOpponentClub(): NpcClub { return $this->opponentClub; }
    public function getGoalsFor(): int { return $this->goalsFor; }
    public function getGoalsAgainst(): int { return $this->goalsAgainst; }
    public function getWeek(): int { return $this->week; }
    public function getSeason(): int { return $this->season; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
