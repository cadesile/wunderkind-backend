<?php

namespace App\Entity;

use App\Enum\LeaderboardCategory;
use App\Repository\LeaderboardEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: LeaderboardEntryRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_leaderboard_club_category_period', columns: ['club_id', 'category', 'period'])]
class LeaderboardEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(inversedBy: 'leaderboardEntries')]
    #[ORM\JoinColumn(nullable: false)]
    private Club $club;

    #[ORM\Column(enumType: LeaderboardCategory::class)]
    private LeaderboardCategory $category;

    /** Score value (earnings in pence, reputation points, HoF points) */
    #[ORM\Column(type: 'bigint', options: ['unsigned' => true, 'default' => 0])]
    private int $score = 0;

    /**
     * Period identifier.
     * Use 'all-time' for lifetime boards, or ISO week string (e.g. '2026-W09') for weekly.
     */
    #[ORM\Column(length: 20)]
    private string $period;

    /** Computed rank — null until the ranking job runs */
    #[ORM\Column(name: 'rank_position', type: 'integer', nullable: true, options: ['unsigned' => true])]
    private ?int $rank = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Club $club, LeaderboardCategory $category, string $period)
    {
        $this->id        = new UuidV7();
        $this->club   = $club;
        $this->category  = $category;
        $this->period    = $period;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getCategory(): LeaderboardCategory { return $this->category; }
    public function getCategoryValue(): string { return $this->category->value; }
    public function getPeriod(): string { return $this->period; }

    public function getScore(): int { return $this->score; }
    public function setScore(int $score): void
    {
        $this->score     = $score;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getRank(): ?int { return $this->rank; }
    public function setRank(?int $rank): void { $this->rank = $rank; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
