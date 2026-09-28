<?php

namespace App\Entity\WorldPack;

use App\Enum\WorldPack\WorldPackGenerationRunStatus;
use App\Repository\WorldPack\WorldPackGenerationRunRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One row per admin-triggered "Regenerate country cache" run — the first-class
 * "run" concept the admin progress UI polls against. Scoped to admin-triggered
 * regenerates only; cron-triggered warms (app:worldpack:warm) call
 * WorldPackCacheService/WorldInitializationService directly and never create a
 * row here, same as before this tracking schema existed.
 *
 * A partial unique index on (country) WHERE status IN ('pending','in_progress')
 * (added via raw SQL in the migration — not expressible as a Doctrine mapping,
 * same category as ActiveCompetition's "one open per template" index) enforces
 * single-flight per country server-side.
 */
#[ORM\Entity(repositoryClass: WorldPackGenerationRunRepository::class)]
#[ORM\Table(name: 'world_pack_generation_run')]
#[ORM\Index(columns: ['country', 'status'], name: 'idx_world_pack_generation_run_country_status')]
class WorldPackGenerationRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(type: 'string', enumType: WorldPackGenerationRunStatus::class)]
    private WorldPackGenerationRunStatus $status;

    /** @var int[] */
    #[ORM\Column(type: 'json')]
    private array $requestedTiers;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @param int[] $requestedTiers */
    public function __construct(string $country, array $requestedTiers)
    {
        $this->id             = new UuidV7();
        $this->country         = $country;
        $this->requestedTiers  = $requestedTiers;
        $this->status           = WorldPackGenerationRunStatus::PENDING;
        $this->createdAt        = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }

    public function getCountry(): string { return $this->country; }

    public function getStatus(): WorldPackGenerationRunStatus { return $this->status; }
    public function setStatus(WorldPackGenerationRunStatus $status): static { $this->status = $status; return $this; }

    /** @return int[] */
    public function getRequestedTiers(): array { return $this->requestedTiers; }

    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }

    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static { $this->finishedAt = $finishedAt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
