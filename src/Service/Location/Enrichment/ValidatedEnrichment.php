<?php

declare(strict_types=1);

namespace App\Service\Location\Enrichment;

use App\Enum\Location\EnrichmentOutcome;

/**
 * The validator's verdict: which fields may be written, and why the others may not.
 *
 * Per-field rather than per-response, which is the whole design. An LLM asked for three
 * numbers routinely gets two right and one absurd, and throwing the good two away because of
 * the bad one would make enrichment far less useful than it is. The rejected half is kept with
 * its reason so a bad prompt or a wrong validation band is diagnosable from the attempt row
 * instead of guessed at.
 */
final readonly class ValidatedEnrichment
{
    /**
     * @param array<string, int|float|string> $accepted field => value to write
     * @param array<string, string>           $rejected field => human-readable reason
     * @param list<string>                    $skipped  fields an admin has locked
     */
    public function __construct(
        public array $accepted = [],
        public array $rejected = [],
        public array $skipped = [],
    ) {}

    /**
     * ACCEPTED only when nothing was refused; PARTIAL when some was written and some refused;
     * REJECTED when nothing usable survived.
     *
     * A locked field is NOT a rejection — the admin's value already stands, so a response whose
     * only "problem" is that every field it offered was locked is still ACCEPTED.
     */
    public function outcome(): EnrichmentOutcome
    {
        if ($this->accepted === []) {
            return $this->rejected === [] && $this->skipped !== []
                ? EnrichmentOutcome::ACCEPTED
                : EnrichmentOutcome::REJECTED;
        }

        return $this->rejected === [] ? EnrichmentOutcome::ACCEPTED : EnrichmentOutcome::PARTIAL;
    }

    public function hasCoordinates(): bool
    {
        return isset($this->accepted['lat'], $this->accepted['lng']);
    }
}
