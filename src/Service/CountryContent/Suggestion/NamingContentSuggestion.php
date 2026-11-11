<?php

declare(strict_types=1);

namespace App\Service\CountryContent\Suggestion;

/**
 * What a provider offered for one country's club-naming content. Untrusted and unvalidated.
 *
 * @see \App\Service\CountryContent\NamingContentValidator — nothing here is stored before it.
 */
final readonly class NamingContentSuggestion
{
    /**
     * @param list<string> $prestigeSuffixes
     * @param list<string> $genericSuffixes
     * @param list<string> $stadiumFormats
     */
    public function __construct(
        public array $prestigeSuffixes = [],
        public array $genericSuffixes = [],
        public array $stadiumFormats = [],
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
    ) {}

    /** @return array<string, list<string>> keyed the way NamingContentValidator expects. */
    public function toInput(): array
    {
        return [
            'prestigeSuffixes' => $this->prestigeSuffixes,
            'genericSuffixes'  => $this->genericSuffixes,
            'stadiumFormats'   => $this->stadiumFormats,
        ];
    }
}
