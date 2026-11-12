<?php

declare(strict_types=1);

namespace App\Service\CountryContent\Suggestion;

/** What a provider offered for one country's name pool. Untrusted and unvalidated. */
final readonly class NamePoolSuggestion
{
    /** @param list<string> $names */
    public function __construct(
        public array $names = [],
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
    ) {}
}
