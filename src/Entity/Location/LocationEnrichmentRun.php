<?php

declare(strict_types=1);

namespace App\Entity\Location;

use App\Entity\Admin;
use App\Enum\Location\EnrichmentRunStatus;
use App\Enum\Location\EnrichmentTrigger;
use App\Repository\Location\LocationEnrichmentRunRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One enrichment batch: what was asked for, by whom, and how it turned out.
 *
 * Modelled on WorldPackGenerationRun — the existing admin-triggered
 * batch-with-per-item-status pattern in this codebase. Two tables here rather than that
 * feature's three, because a location's CURRENT state already lives on
 * Location::$enrichmentStatus (the admin overview and the lazy read path both need it there),
 * so the child table carries history instead of duplicating state.
 *
 * Counters are advanced by the message handler as each attempt lands, so a run that is still
 * draining shows real progress rather than nothing until it finishes. They are therefore
 * eventually-consistent with the attempt rows, and the attempt rows are the authority if the
 * two ever disagree.
 */
#[ORM\Entity(repositoryClass: LocationEnrichmentRunRepository::class)]
#[ORM\Table(name: 'location_enrichment_run')]
#[ORM\Index(columns: ['started_at'], name: 'idx_location_enrichment_run_started')]
class LocationEnrichmentRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /**
     * Column is `trigger_type`: TRIGGER is a reserved SQL word. PostgreSQL happens to accept
     * it unquoted, but this table is written to with raw SQL (see
     * LocationEnrichmentRunRepository::recordOutcome()) and the codebase already took this
     * decision once, renaming LeaderboardEntry's `rank` to `rank_position` for the same reason.
     */
    #[ORM\Column(name: 'trigger_type', length: 16, enumType: EnrichmentTrigger::class)]
    private EnrichmentTrigger $trigger;

    /**
     * SET NULL — a run is a historical fact and must outlive the admin account that started
     * it. Null also covers a run triggered by the lazy read path, which has no admin.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Admin $requestedBy = null;

    /** Which country the run was scoped to, or null for a global sweep. */
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $countryCode = null;

    #[ORM\Column(length: 40)]
    private string $provider;

    #[ORM\Column(length: 60)]
    private string $model;

    #[ORM\Column]
    private int $queued = 0;

    #[ORM\Column]
    private int $succeeded = 0;

    #[ORM\Column]
    private int $needsReview = 0;

    #[ORM\Column]
    private int $failed = 0;

    #[ORM\Column(length: 16, enumType: EnrichmentRunStatus::class)]
    private EnrichmentRunStatus $status = EnrichmentRunStatus::RUNNING;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(
        EnrichmentTrigger $trigger,
        string $provider,
        string $model,
        ?string $countryCode = null,
        ?Admin $requestedBy = null,
    ) {
        $this->id          = new UuidV7();
        $this->trigger     = $trigger;
        $this->provider    = $provider;
        $this->model       = $model;
        $this->countryCode = $countryCode;
        $this->requestedBy = $requestedBy;
        $this->startedAt   = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getTrigger(): EnrichmentTrigger { return $this->trigger; }
    public function getRequestedBy(): ?Admin { return $this->requestedBy; }
    public function getCountryCode(): ?string { return $this->countryCode; }
    public function getProvider(): string { return $this->provider; }
    public function getModel(): string { return $this->model; }

    public function getQueued(): int { return $this->queued; }
    public function setQueued(int $queued): void { $this->queued = $queued; }

    public function getSucceeded(): int { return $this->succeeded; }
    public function getNeedsReview(): int { return $this->needsReview; }
    public function getFailed(): int { return $this->failed; }

    /**
     * Set the settled counts outright, for a run whose outcome is known all at once.
     *
     * The enrichment path must NOT use these. There, outcomes arrive one message at a time
     * across concurrent workers, so the counters have to be incremented atomically in SQL —
     * LocationEnrichmentRunRepository::recordOutcome() exists precisely so two workers
     * finishing together cannot both read 4 and both write 5.
     *
     * The one caller left is the CLI's single-call town suggestion
     * (TownSuggestionService::suggestForCountry(), recorded as ADMIN_TOWN_SUGGESTION), which has
     * no such race: it is one synchronous call whose results are all in hand before anything is
     * written. Reading its totals back out of the database a row at a time would be slower and
     * no safer.
     *
     * The QUEUED town pull is not that and must not use this either — its batches settle one
     * message at a time, so it goes through recordBatchOutcome() for the same reason enrichment
     * goes through recordOutcome(), plus one more: the handler calls it after importNames() has
     * cleared the entity manager, and a write through a detached run would be lost in silence.
     */
    public function setSettled(int $succeeded, int $failed): void
    {
        $this->succeeded = $succeeded;
        $this->failed    = $failed;
    }

    public function getSettled(): int { return $this->succeeded + $this->needsReview + $this->failed; }

    public function getStatus(): EnrichmentRunStatus { return $this->status; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }

    public function markComplete(): void
    {
        $this->status     = EnrichmentRunStatus::COMPLETE;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function markCancelled(): void
    {
        $this->status     = EnrichmentRunStatus::CANCELLED;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        $scope = $this->countryCode ?? 'all';

        return "{$this->trigger->value} ({$scope}) {$this->getSettled()}/{$this->queued}";
    }
}
