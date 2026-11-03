<?php

declare(strict_types=1);

namespace App\Service\Location\Enrichment;

/**
 * What a provider said about one location, parsed but NOT yet trusted.
 *
 * Every field is nullable because a provider may legitimately decline one; nothing here has
 * passed EnrichmentValidator yet, so a value in this object may still be a coordinate in the
 * Atlantic. Do not write any of it to a Location directly — go through the validator.
 *
 * `raw` is kept verbatim for the attempt row, so an odd answer can be read back exactly as it
 * arrived rather than reconstructed from the fields that survived parsing.
 */
final readonly class EnrichmentResult
{
    /**
     * @param array<string, mixed> $raw the provider's response as parsed, for forensics
     */
    public function __construct(
        public ?float $lat = null,
        public ?float $lng = null,
        public ?int $population = null,
        public ?int $landPence = null,
        public ?string $region = null,
        public array $raw = [],
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
    ) {}

    public function hasCoordinates(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /** True when the provider answered but offered nothing we asked for. */
    public function isEmpty(): bool
    {
        return !$this->hasCoordinates()
            && $this->population === null
            && $this->landPence === null
            && $this->region === null;
    }
}
