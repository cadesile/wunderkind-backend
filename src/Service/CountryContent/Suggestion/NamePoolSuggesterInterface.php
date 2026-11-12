<?php

declare(strict_types=1);

namespace App\Service\CountryContent\Suggestion;

use App\Entity\Location\CountryProfile;

/**
 * Asks an AI provider for more names of one kind for one country.
 *
 * ONE LIST PER CALL, not both. A hundred and fifty first names is a short generation; asking
 * for three hundred strings across two lists in one reply doubles it and halves the chance of
 * getting either back intact — the lesson ClaudeTownSuggester records at length after a third
 * of its batches truncated on their own reasoning tokens.
 *
 * Never writes, never validates, throws rather than half-answering.
 */
interface NamePoolSuggesterInterface
{
    /**
     * @param 'firstNames'|'lastNames' $list
     * @param list<string>             $exclude names already held, which must not come back
     * @param int                      $want    the shortfall, not the target
     *
     * @throws NamePoolUnavailableException when the provider cannot be reached or is unconfigured
     */
    public function suggest(CountryProfile $country, string $list, array $exclude, int $want): NamePoolSuggestion;

    public function provider(): string;

    public function model(): string;

    public function promptVersion(): string;

    /** A no-I/O read. NOT a connection check — this screen makes no provider call on render. */
    public function isConfigured(): bool;
}
