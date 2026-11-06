<?php

declare(strict_types=1);

namespace App\Service\Location\Suggestion;

/**
 * What one call to the model produced, before anything is written.
 *
 * Token counts ride along so the caller can log what the run cost; the enrichment path puts
 * the equivalent figures on an attempt row, which a suggestion has no room for because the
 * locations it is about do not exist yet.
 */
final readonly class TownSuggestionResult
{
    /** @param list<SuggestedTown> $towns */
    public function __construct(
        public array $towns,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->towns === [];
    }
}
