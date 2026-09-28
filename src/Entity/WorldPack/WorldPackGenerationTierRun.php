<?php

namespace App\Entity\WorldPack;

use App\Enum\WorldPack\WorldPackGenerationTierRunStatus;
use App\Repository\WorldPack\WorldPackGenerationTierRunRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One row per tier within a WorldPackGenerationRun. country/tier are denormalized
 * off the run for query convenience. agentPoolIds is the bounded agent subset
 * selected once for this tier (WorldInitializationService::selectBoundedAgentPool()),
 * stored here so every club message in the tier reads the same fixed subset rather
 * than re-rolling it per club.
 *
 * claimedAt is the assembly claim lock — same atomic-conditional-UPDATE idiom as
 * CompetitionRound's drawLockedAt/resolveLockedAt.
 */
#[ORM\Entity(repositoryClass: WorldPackGenerationTierRunRepository::class)]
#[ORM\Table(name: 'world_pack_generation_tier_run')]
#[ORM\UniqueConstraint(name: 'uq_world_pack_generation_tier_run_run_tier', columns: ['run_id', 'tier'])]
class WorldPackGenerationTierRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(targetEntity: WorldPackGenerationRun::class)]
    #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'CASCADE')]
    private WorldPackGenerationRun $run;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(type: 'smallint')]
    private int $tier;

    #[ORM\Column(type: 'string', enumType: WorldPackGenerationTierRunStatus::class)]
    private WorldPackGenerationTierRunStatus $status;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $totalClubCount = 0;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $completedClubCount = 0;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $failedClubCount = 0;

    /** @var string[]|null Bounded agent-pool UUIDs for this tier, set once at tier-claim time. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $agentPoolIds = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    public function __construct(WorldPackGenerationRun $run, string $country, int $tier)
    {
        $this->id      = new UuidV7();
        $this->run      = $run;
        $this->country  = $country;
        $this->tier      = $tier;
        $this->status     = WorldPackGenerationTierRunStatus::PENDING;
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getRun(): WorldPackGenerationRun { return $this->run; }

    public function getCountry(): string { return $this->country; }

    public function getTier(): int { return $this->tier; }

    public function getStatus(): WorldPackGenerationTierRunStatus { return $this->status; }
    public function setStatus(WorldPackGenerationTierRunStatus $status): static { $this->status = $status; return $this; }

    public function getTotalClubCount(): int { return $this->totalClubCount; }
    public function setTotalClubCount(int $totalClubCount): static { $this->totalClubCount = $totalClubCount; return $this; }

    public function getCompletedClubCount(): int { return $this->completedClubCount; }
    public function setCompletedClubCount(int $completedClubCount): static { $this->completedClubCount = $completedClubCount; return $this; }

    public function getFailedClubCount(): int { return $this->failedClubCount; }
    public function setFailedClubCount(int $failedClubCount): static { $this->failedClubCount = $failedClubCount; return $this; }

    /** @return string[]|null */
    public function getAgentPoolIds(): ?array { return $this->agentPoolIds; }
    /** @param string[] $agentPoolIds */
    public function setAgentPoolIds(array $agentPoolIds): static { $this->agentPoolIds = $agentPoolIds; return $this; }

    public function getClaimedAt(): ?\DateTimeImmutable { return $this->claimedAt; }
    public function setClaimedAt(?\DateTimeImmutable $claimedAt): static { $this->claimedAt = $claimedAt; return $this; }

    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }

    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static { $this->finishedAt = $finishedAt; return $this; }

    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): static { $this->errorMessage = $errorMessage === null ? null : mb_substr($errorMessage, 0, 2000); return $this; }
}
