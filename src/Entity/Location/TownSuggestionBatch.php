<?php

declare(strict_types=1);

namespace App\Entity\Location;

use App\Enum\Location\TownSuggestionBatchStatus;
use App\Repository\Location\TownSuggestionBatchRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One batch of a queued "Pull towns" run: one AI call, one import, one row.
 *
 * WHY A CHILD TABLE AND NOT JUST COUNTERS. Three things a counter on the run physically cannot
 * do, and all three are the point of this feature:
 *   1. `offeredNames` is a LIST, and the next batch needs it — see TownSuggestionService's
 *      exclusion list. Without it, batch four asks the model the same question batch one did
 *      and pays for the same answer.
 *   2. A retry needs a stable id to re-dispatch against.
 *   3. `errorMessage` makes "batch 4 failed" diagnosable instead of merely red.
 *
 * Two levels, not WorldPackGenerationRun's three. That feature needs a third because a tier
 * fans out across clubs, each carrying its own generated payload; a batch has no sub-items
 * worth rows.
 *
 * batchIndex AND totalBatches are both denormalised onto the row so "3 of 6" costs no second
 * query — the same reason WorldPackGenerationTierRun carries its run's country and tier.
 *
 * WRITTEN WITH RAW SQL, NOT THE ORM, once the batch is running. LocationSeedService::importNames()
 * ends in `em->clear()` — but only when it actually imported something — so an entity held
 * across it is detached intermittently, and an ORM write after it vanishes without error. The
 * handler therefore holds no entity across the import and updates this row through
 * TownSuggestionBatchRepository's statements. Doing it for the batch row as well as the run
 * counters means one rule instead of two.
 */
#[ORM\Entity(repositoryClass: TownSuggestionBatchRepository::class)]
#[ORM\Table(name: 'town_suggestion_batch')]
#[ORM\UniqueConstraint(name: 'uq_town_suggestion_batch_run_index', columns: ['run_id', 'batch_index'])]
class TownSuggestionBatch
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\ManyToOne(targetEntity: LocationEnrichmentRun::class)]
    #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'CASCADE')]
    private LocationEnrichmentRun $run;

    /** Denormalised off the run so a batch row can be read and rendered on its own. */
    #[ORM\Column(length: 2)]
    private string $countryCode;

    /** 1-based, so it reads as "batch 3 of 6" without arithmetic in the template. */
    #[ORM\Column(type: 'smallint')]
    private int $batchIndex;

    #[ORM\Column(type: 'smallint')]
    private int $totalBatches;

    #[ORM\Column(type: 'string', enumType: TownSuggestionBatchStatus::class)]
    private TownSuggestionBatchStatus $status = TownSuggestionBatchStatus::PENDING;

    /** How many towns this batch asked the model for. */
    #[ORM\Column(type: 'smallint')]
    private int $requested;

    /** How many it offered back. */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $offered = 0;

    /** How many survived the validator. */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $accepted = 0;

    /** How many became rows. The figure the operator actually cares about. */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $imported = 0;

    /** Offered but already held — expected, not a fault. The prompt's exclusion list is capped. */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $alreadyHeld = 0;

    /**
     * Every name this batch offered, accepted or not.
     *
     * The exclusion input for every LATER batch in the run, which is the whole reason this
     * column exists. Rejected and already-held names are kept deliberately: a name the model
     * offered once it will offer again, and telling it not to is cheaper than paying for the
     * repeat.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $offeredNames = null;

    /**
     * Self-managed retry count.
     *
     * The handler catches its own failures and re-dispatches rather than letting the exception
     * reach Messenger, so this is the ONLY retry counter — the reason
     * WarmWorldPackClubMessageHandler does the same. Letting Messenger's invisible retries run
     * alongside a progress UI makes the bar flash "failed" and then un-fail itself.
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(
        LocationEnrichmentRun $run,
        string $countryCode,
        int $batchIndex,
        int $totalBatches,
        int $requested,
    ) {
        $this->id           = new UuidV7();
        $this->run          = $run;
        $this->countryCode  = strtoupper($countryCode);
        $this->batchIndex   = $batchIndex;
        $this->totalBatches = $totalBatches;
        $this->requested    = $requested;
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getRun(): LocationEnrichmentRun { return $this->run; }
    public function getCountryCode(): string { return $this->countryCode; }
    public function getBatchIndex(): int { return $this->batchIndex; }
    public function getTotalBatches(): int { return $this->totalBatches; }
    public function getRequested(): int { return $this->requested; }

    public function getStatus(): TownSuggestionBatchStatus { return $this->status; }
    public function setStatus(TownSuggestionBatchStatus $status): static { $this->status = $status; return $this; }

    public function getOffered(): int { return $this->offered; }
    public function setOffered(int $offered): static { $this->offered = $offered; return $this; }

    public function getAccepted(): int { return $this->accepted; }
    public function setAccepted(int $accepted): static { $this->accepted = $accepted; return $this; }

    public function getImported(): int { return $this->imported; }
    public function setImported(int $imported): static { $this->imported = $imported; return $this; }

    public function getAlreadyHeld(): int { return $this->alreadyHeld; }
    public function setAlreadyHeld(int $alreadyHeld): static { $this->alreadyHeld = $alreadyHeld; return $this; }

    /** @return list<string> */
    public function getOfferedNames(): array { return $this->offeredNames ?? []; }

    /** @param list<string> $names */
    public function setOfferedNames(array $names): static { $this->offeredNames = $names; return $this; }

    public function getAttempts(): int { return $this->attempts; }
    public function setAttempts(int $attempts): static { $this->attempts = $attempts; return $this; }

    public function getErrorMessage(): ?string { return $this->errorMessage; }

    /** Truncated like WorldPackGenerationTierRun's: a provider stack trace must not fill the column. */
    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage === null ? null : mb_substr($errorMessage, 0, 2000);

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }

    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static { $this->finishedAt = $finishedAt; return $this; }

    public function __toString(): string
    {
        return \sprintf('batch %d of %d (%s)', $this->batchIndex, $this->totalBatches, $this->status->value);
    }
}
