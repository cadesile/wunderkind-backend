<?php

namespace App\Entity\WorldPack;

use App\Entity\NpcClub;
use App\Enum\WorldPack\WorldPackGenerationClubRunStatus;
use App\Repository\WorldPack\WorldPackGenerationClubRunRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One row per club within a WorldPackGenerationTierRun — the granular level the
 * admin progress UI actually renders. clubName is denormalized so it survives
 * even if the NpcClub row changes. snapshotJson holds the built club snapshot
 * (players/staff/scouts) between "this club finished generating" and "the tier
 * finished assembling" — nulled out immediately after successful tier assembly
 * (already folded into CountryWorldPackCache.payload by then; no reason to keep
 * two copies), while the row itself (status/timestamps/error) is retained for
 * history so a run stays reviewable after it finishes.
 */
#[ORM\Entity(repositoryClass: WorldPackGenerationClubRunRepository::class)]
#[ORM\Table(name: 'world_pack_generation_club_run')]
#[ORM\UniqueConstraint(name: 'uq_world_pack_generation_club_run_tier_club', columns: ['tier_run_id', 'npc_club_id'])]
#[ORM\Index(columns: ['tier_run_id', 'status'], name: 'idx_world_pack_generation_club_run_tier_status')]
class WorldPackGenerationClubRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(targetEntity: WorldPackGenerationTierRun::class)]
    #[ORM\JoinColumn(name: 'tier_run_id', nullable: false, onDelete: 'CASCADE')]
    private WorldPackGenerationTierRun $tierRun;

    #[ORM\ManyToOne(targetEntity: NpcClub::class)]
    #[ORM\JoinColumn(name: 'npc_club_id', nullable: false, onDelete: 'CASCADE')]
    private NpcClub $npcClub;

    #[ORM\Column(length: 255)]
    private string $clubName;

    #[ORM\Column(type: 'string', enumType: WorldPackGenerationClubRunStatus::class)]
    private WorldPackGenerationClubRunStatus $status;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $snapshotJson = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(WorldPackGenerationTierRun $tierRun, NpcClub $npcClub)
    {
        $this->id       = new UuidV7();
        $this->tierRun   = $tierRun;
        $this->npcClub   = $npcClub;
        $this->clubName  = $npcClub->getName();
        $this->status     = WorldPackGenerationClubRunStatus::PENDING;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getTierRun(): WorldPackGenerationTierRun { return $this->tierRun; }

    public function getNpcClub(): NpcClub { return $this->npcClub; }

    public function getClubName(): string { return $this->clubName; }

    public function getStatus(): WorldPackGenerationClubRunStatus { return $this->status; }
    public function setStatus(WorldPackGenerationClubRunStatus $status): static { $this->status = $status; return $this; }

    public function getAttempts(): int { return $this->attempts; }
    public function setAttempts(int $attempts): static { $this->attempts = $attempts; return $this; }

    public function getClaimedAt(): ?\DateTimeImmutable { return $this->claimedAt; }
    public function setClaimedAt(?\DateTimeImmutable $claimedAt): static { $this->claimedAt = $claimedAt; return $this; }

    /** @return array<string, mixed>|null */
    public function getSnapshotJson(): ?array { return $this->snapshotJson; }
    /** @param array<string, mixed>|null $snapshotJson */
    public function setSnapshotJson(?array $snapshotJson): static { $this->snapshotJson = $snapshotJson; return $this; }

    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): static { $this->errorMessage = $errorMessage === null ? null : mb_substr($errorMessage, 0, 2000); return $this; }

    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }

    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static { $this->finishedAt = $finishedAt; return $this; }
}
