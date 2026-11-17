<?php

namespace App\Entity\WorldPack;

use App\Enum\WorldPack\WorldPackGenerationRunStatus;
use App\Repository\WorldPack\WorldPackGenerationRunRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One row per admin-triggered "Regenerate country cache" run — the first-class
 * "run" concept the admin progress UI polls against. Scoped to admin-triggered
 * regenerates only; the unattended top-up that keeps each country's WorldPackSet
 * buffer stocked calls WorldInitializationService directly and never creates a row
 * here, same as the cron warms that preceded it and as before this tracking schema
 * existed.
 *
 * A run now covers `setCount` WorldPackSets at once (the admin regenerate builds a
 * country's whole buffer in one go), so it holds setCount x tiers
 * WorldPackGenerationTierRun children and the SET each tier belongs to is recorded
 * on the tier run, not here. That is deliberate: it keeps this table — and its
 * single-flight index below — the per-COUNTRY unit it has always been, so nothing
 * about admin concurrency changes.
 *
 * A partial unique index on (country) WHERE status IN ('pending','in_progress')
 * (added via raw SQL in the migration — not expressible as a Doctrine mapping,
 * same category as ActiveCompetition's "one open per template" index) enforces
 * single-flight per country server-side.
 */
#[ORM\Entity(repositoryClass: WorldPackGenerationRunRepository::class)]
#[ORM\Table(name: 'world_pack_generation_run')]
#[ORM\Index(columns: ['country', 'status'], name: 'idx_world_pack_generation_run_country_status')]
// Partial unique index: one in-flight run per country.
#[ORM\UniqueConstraint(
    name: 'uq_world_pack_generation_run_one_active_per_country',
    columns: ['country'],
    options: ['where' => "((status)::text = ANY (ARRAY[('pending'::character varying)::text, ('in_progress'::character varying)::text]))"],
)]
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

    /**
     * How many WorldPackSets this run builds. Default 1 so every row that predates the
     * buffer reads correctly rather than as zero.
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $setCount = 1;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @param int[] $requestedTiers */
    public function __construct(string $country, array $requestedTiers, int $setCount = 1)
    {
        $this->id             = new UuidV7();
        $this->country         = $country;
        $this->requestedTiers  = $requestedTiers;
        $this->setCount        = $setCount;
        $this->status           = WorldPackGenerationRunStatus::PENDING;
        $this->createdAt        = new \DateTimeImmutable();
    }

    public function getSetCount(): int { return $this->setCount; }
    public function setSetCount(int $count): static { $this->setCount = $count; return $this; }

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
