<?php

declare(strict_types=1);

namespace App\Entity\Location;

use App\Enum\Location\EnrichmentOutcome;
use App\Repository\Location\LocationEnrichmentAttemptRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

/**
 * One call to an AI provider for one location: what was asked, what came back, what was kept.
 *
 * This is the forensic record, and the only place the provider's raw answer is retained. It
 * exists because "believable realism" is a quality judgement that someone eventually has to
 * audit: when a town is priced oddly, the question is whether the model said something silly
 * or whether the validator's band is wrong, and nothing else in the schema can answer that.
 *
 * READ PROFILE — treat `rawResponseJson` as a split-row column. It is written once and read
 * only by admin forensics, while `location` is read in bulk on a public endpoint. ORM 3 removed
 * PARTIAL hydration, so anything that hydrates these rows decodes the whole payload: never
 * load them on a page listing many locations, and fetch in bulk via
 * LocationEnrichmentAttemptRepository::findByLocations() when more than one is genuinely
 * needed. Location declares NO inverse association here, deliberately.
 *
 * `acceptedFields` / `rejectedFields` are the pair that make a bad prompt diagnosable rather
 * than merely disappointing: validation is per field, so a response can be half-usable, and
 * the row records exactly which half and why.
 *
 * `run` is SET NULL, not CASCADE — pruning old run headers must not destroy the attempt
 * history, the same reasoning as UserLedger::$sourceSyncRecord surviving a sync-record purge.
 */
#[ORM\Entity(repositoryClass: LocationEnrichmentAttemptRepository::class)]
#[ORM\Table(name: 'location_enrichment_attempt')]
#[ORM\Index(columns: ['location_id', 'created_at'], name: 'idx_lea_location_created')]
#[ORM\Index(columns: ['run_id'], name: 'idx_lea_run')]
#[ORM\Index(columns: ['outcome', 'created_at'], name: 'idx_lea_outcome_created')]
class LocationEnrichmentAttempt
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    /** CASCADE — an attempt describes one location and is meaningless without it. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Location $location;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LocationEnrichmentRun $run = null;

    #[ORM\Column(length: 40)]
    private string $provider;

    #[ORM\Column(length: 60)]
    private string $model;

    /** Bumped whenever the prompt changes, so a batch of odd answers can be traced to it. */
    #[ORM\Column(length: 20)]
    private string $promptVersion;

    /** The provider's answer, exactly as parsed. Never read on a bulk path — see the docblock. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawResponseJson = null;

    /** @var array<string, mixed>|null field => value written */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $acceptedFields = null;

    /** @var array<string, string>|null field => why it was refused */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rejectedFields = null;

    #[ORM\Column(nullable: true)]
    private ?int $inputTokens = null;

    #[ORM\Column(nullable: true)]
    private ?int $outputTokens = null;

    #[ORM\Column(length: 12, enumType: EnrichmentOutcome::class)]
    private EnrichmentOutcome $outcome;

    /** Truncated to 255, the DeletionRequest::$failureReason convention. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $errorMessage = null;

    /** Round-trip time, milliseconds — the cheapest way to spot a provider degrading. */
    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Location $location,
        string $provider,
        string $model,
        string $promptVersion,
        EnrichmentOutcome $outcome,
        ?LocationEnrichmentRun $run = null,
    ) {
        $this->id            = new UuidV7();
        $this->location      = $location;
        $this->provider      = $provider;
        $this->model         = $model;
        $this->promptVersion = $promptVersion;
        $this->outcome       = $outcome;
        $this->run           = $run;
        $this->createdAt     = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getLocation(): Location { return $this->location; }
    public function getRun(): ?LocationEnrichmentRun { return $this->run; }
    public function getProvider(): string { return $this->provider; }
    public function getModel(): string { return $this->model; }
    public function getPromptVersion(): string { return $this->promptVersion; }

    public function getRawResponseJson(): ?array { return $this->rawResponseJson; }
    public function setRawResponseJson(?array $raw): void { $this->rawResponseJson = $raw; }

    public function getAcceptedFields(): ?array { return $this->acceptedFields; }
    public function setAcceptedFields(?array $fields): void { $this->acceptedFields = $fields; }

    public function getRejectedFields(): ?array { return $this->rejectedFields; }
    public function setRejectedFields(?array $fields): void { $this->rejectedFields = $fields; }

    public function getInputTokens(): ?int { return $this->inputTokens; }
    public function getOutputTokens(): ?int { return $this->outputTokens; }

    public function setTokenUsage(?int $input, ?int $output): void
    {
        $this->inputTokens  = $input;
        $this->outputTokens = $output;
    }

    public function getOutcome(): EnrichmentOutcome { return $this->outcome; }

    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $message): void
    {
        $this->errorMessage = $message === null ? null : mb_substr($message, 0, 255);
    }

    public function getDurationMs(): ?int { return $this->durationMs; }
    public function setDurationMs(?int $durationMs): void { $this->durationMs = $durationMs; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** Pretty-printed raw response, for the admin detail page only. */
    public function getRawResponsePretty(): string
    {
        if ($this->rawResponseJson === null) {
            return '';
        }

        return (string) json_encode(
            $this->rawResponseJson,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    public function __toString(): string
    {
        return "{$this->location->getTown()} {$this->outcome->value} ({$this->model})";
    }
}
