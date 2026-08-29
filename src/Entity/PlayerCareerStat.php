<?php

namespace App\Entity;

use App\Repository\PlayerCareerStatRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Season-to-date per-player stats for a club's active roster.
 *
 * Player rows in this app are pool-only and deleted on consumption (see CLAUDE.md
 * hybrid model), so club rosters have no durable server-side Player row to attach
 * career stats to. This entity is keyed by the client-generated playerId string
 * instead — no FK to Player — mirroring how Transfer already stores a denormalized
 * playerName snapshot when the source of truth is ephemeral.
 */
#[ORM\Entity(repositoryClass: PlayerCareerStatRepository::class)]
#[ORM\UniqueConstraint(name: 'uq_player_career_stat_club_player', columns: ['club_id', 'player_id'])]
#[ORM\Index(name: 'idx_player_career_stat_goals', columns: ['goals'])]
#[ORM\Index(name: 'idx_player_career_stat_assists', columns: ['assists'])]
#[ORM\Index(name: 'idx_player_career_stat_appearances', columns: ['appearances'])]
class PlayerCareerStat
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Club $club;

    /** Client-generated player identity — not a FK to Player. */
    #[ORM\Column(name: 'player_id', length: 64)]
    private string $playerId;

    /** Denormalized display snapshot, refreshed each sync. */
    #[ORM\Column(name: 'player_name', length: 100)]
    private string $playerName;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $appearances = 0;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $goals = 0;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $assists = 0;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Club $club, string $playerId, string $playerName)
    {
        $this->id         = new UuidV7();
        $this->club       = $club;
        $this->playerId   = $playerId;
        $this->playerName = $playerName;
        $this->updatedAt   = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getPlayerId(): string { return $this->playerId; }
    public function getPlayerName(): string { return $this->playerName; }
    public function getAppearances(): int { return $this->appearances; }
    public function getGoals(): int { return $this->goals; }
    public function getAssists(): int { return $this->assists; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /**
     * playerStats is sent as a season-to-date cumulative snapshot, not a per-tick
     * delta — overwrite rather than accumulate, so a replayed sync can't double-count.
     */
    public function applySnapshot(int $appearances, int $goals, int $assists, string $playerName): void
    {
        $this->appearances = $appearances;
        $this->goals        = $goals;
        $this->assists      = $assists;
        $this->playerName   = $playerName;
        $this->updatedAt     = new \DateTimeImmutable();
    }
}
