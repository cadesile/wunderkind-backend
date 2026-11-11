<?php

declare(strict_types=1);

namespace App\Service\CountryContent\Suggestion;

use App\Entity\Location\CountryProfile;

/**
 * Asks an AI provider for a country's club-naming content.
 *
 * Same contract as the other two suggesters in this codebase: never writes, never validates,
 * throws rather than half-answering. What comes back goes through NamingContentValidator,
 * which refuses a category outright rather than storing a list that would make every club in
 * a size band read the same — or a stadium template that would throw inside sprintf().
 */
interface NamingContentSuggesterInterface
{
    /** @throws NamingContentUnavailableException when the provider cannot be reached or is unconfigured */
    public function suggest(CountryProfile $country): NamingContentSuggestion;

    public function provider(): string;

    public function model(): string;

    public function promptVersion(): string;

    /**
     * Whether the provider is configured — a no-I/O read.
     *
     * NOT a connection check. The locations dashboard makes no live provider call today and
     * must not start: its own docblock makes a point of asking the readiness verdict for one
     * country only, and the enrichment screen already had to cache its connection check for a
     * minute after an uncached one hung that page for the client's full timeout.
     */
    public function isConfigured(): bool;
}
