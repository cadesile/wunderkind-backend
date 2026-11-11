<?php

declare(strict_types=1);

namespace App\Service\Location\CountryEnrichment;

/**
 * The validator's per-field verdict on one country's answer.
 *
 * THREE BUCKETS, NOT TWO, and the third is the one that makes the admin flash honest.
 * `skipped` is a field the country already had a value for, on a run that was not told to
 * overwrite — that is not a rejection and must not read like one, or an operator will go
 * looking for a fault in an answer that was simply not needed.
 *
 * Mirrors ValidatedEnrichment, whose `skipped` carries admin-locked fields for the same
 * reason.
 */
final readonly class ValidatedCountryProfile
{
    /**
     * @param array<string, mixed>  $accepted field => value to write
     * @param array<string, string> $rejected field => why it was refused
     * @param list<string>          $skipped  field => already set, and this run is not overwriting
     */
    public function __construct(
        public array $accepted = [],
        public array $rejected = [],
        public array $skipped = [],
    ) {}

    public function hasAnything(): bool
    {
        return $this->accepted !== [];
    }
}
