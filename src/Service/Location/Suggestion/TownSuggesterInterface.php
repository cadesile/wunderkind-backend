<?php

declare(strict_types=1);

namespace App\Service\Location\Suggestion;

use App\Entity\Location\CountryProfile;

/**
 * Asks a model for town names a country does not have yet.
 *
 * Mirrors LocationEnricherInterface deliberately, down to checkConnection(): the admin screen
 * already knows how to render that verdict and how to disable a paid button when it is not ok,
 * and a second AI feature with a different shape would need its own version of all of it.
 */
interface TownSuggesterInterface
{
    /**
     * @param list<string> $excludeNames towns already held, to keep out of the answer. This is
     *                                   an optimisation and NOT the guarantee — a country can
     *                                   hold thousands of names and the prompt cannot carry
     *                                   them all, so the caller must still dedupe what comes
     *                                   back against the full set.
     * @param int          $max          how many to ask for; the reply is ordered largest-first
     *                                   so a cap drops the smallest towns
     */
    public function suggest(CountryProfile $country, array $excludeNames, int $max): TownSuggestionResult;

    public function provider(): string;

    public function model(): string;

    /** Bumped on any change to the prompt or the schema, so odd batches are traceable. */
    public function promptVersion(): string;

    /** @return array{ok: bool, detail: string} */
    public function checkConnection(): array;
}
