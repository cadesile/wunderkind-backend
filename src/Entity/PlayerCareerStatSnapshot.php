<?php

namespace App\Entity;

use App\Repository\PlayerCareerStatSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * Append-only per-sync history of a player's season-to-date stats, one row per
 * player per sync. Never updated or deleted — this is what makes "all-time"
 * reconstructable across season resets, since PlayerCareerStat itself is
 * overwritten in place and only ever reflects the current season.
 *
 * Sourced from the same client-reported playerStats that feed PlayerCareerStat,
 * written alongside it (see SyncService::processPlayerCareerStats). Backfilled
 * for pre-existing history from sync_record.payload by
 * app:backfill-player-career-stat-snapshots.
 */
#[ORM\Entity(repositoryClass: PlayerCareerStatSnapshotRepository::class)]
#[ORM\Index(name: 'idx_player_career_stat_snapshot_club_player_recorded', columns: ['club_id', 'player_id', 'recorded_at'])]
class PlayerCareerStatSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Club $club;

    /** Client-generated player identity — not a FK to Player, mirrors PlayerCareerStat. */
    #[ORM\Column(name: 'player_id', length: 64)]
    private string $playerId;

    #[ORM\Column(name: 'player_name', length: 100)]
    private string $playerName;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $appearances = 0;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $goals = 0;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true, 'default' => 0])]
    private int $assists = 0;

    /** Server receipt time of the sync this snapshot came from — never client-supplied. */
    #[ORM\Column(name: 'recorded_at')]
    private \DateTimeImmutable $recordedAt;

    /** Traceability back to the originating sync; nullable so the row survives a purged SyncRecord. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'sync_record_id', nullable: true, onDelete: 'SET NULL')]
    private ?SyncRecord $syncRecord = null;

    public function __construct(
        Club $club,
        string $playerId,
        string $playerName,
        int $appearances,
        int $goals,
        int $assists,
        \DateTimeImmutable $recordedAt,
        ?SyncRecord $syncRecord = null,
    ) {
        $this->id          = new UuidV7();
        $this->club        = $club;
        $this->playerId    = $playerId;
        $this->playerName  = $playerName;
        $this->appearances = $appearances;
        $this->goals       = $goals;
        $this->assists     = $assists;
        $this->recordedAt  = $recordedAt;
        $this->syncRecord  = $syncRecord;
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getClub(): Club { return $this->club; }
    public function getPlayerId(): string { return $this->playerId; }
    public function getPlayerName(): string { return $this->playerName; }
    public function getAppearances(): int { return $this->appearances; }
    public function getGoals(): int { return $this->goals; }
    public function getAssists(): int { return $this->assists; }
    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }
    public function getSyncRecord(): ?SyncRecord { return $this->syncRecord; }
}
