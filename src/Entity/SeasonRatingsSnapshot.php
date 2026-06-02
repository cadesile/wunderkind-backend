<?php

namespace App\Entity;

use App\Repository\SeasonRatingsSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: SeasonRatingsSnapshotRepository::class)]
#[ORM\Table(name: 'season_ratings_snapshot')]
#[ORM\Index(columns: ['season', 'tier'], name: 'idx_srs_season_tier')]
class SeasonRatingsSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(type: 'smallint')]
    private int $season;

    #[ORM\Column(name: 'week_num', type: 'smallint')]
    private int $weekNum;

    #[ORM\Column(type: 'smallint')]
    private int $tier;

    #[ORM\Column(name: 'club_id', length: 36)]
    private string $clubId;

    #[ORM\Column(name: 'club_name', length: 100)]
    private string $clubName;

    #[ORM\Column(name: 'overall_rating', type: 'smallint')]
    private int $overallRating;

    #[ORM\Column(name: 'expected_position', type: 'smallint')]
    private int $expectedPosition;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        int $season,
        int $weekNum,
        int $tier,
        string $clubId,
        string $clubName,
        int $overallRating,
        int $expectedPosition,
    ) {
        $this->id               = new UuidV7();
        $this->season           = $season;
        $this->weekNum          = $weekNum;
        $this->tier             = $tier;
        $this->clubId           = $clubId;
        $this->clubName         = $clubName;
        $this->overallRating    = $overallRating;
        $this->expectedPosition = $expectedPosition;
        $this->createdAt        = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getSeason(): int { return $this->season; }
    public function getWeekNum(): int { return $this->weekNum; }
    public function getTier(): int { return $this->tier; }
    public function getClubId(): string { return $this->clubId; }
    public function getClubName(): string { return $this->clubName; }
    public function getOverallRating(): int { return $this->overallRating; }
    public function getExpectedPosition(): int { return $this->expectedPosition; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
