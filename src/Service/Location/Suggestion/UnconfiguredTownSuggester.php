<?php

declare(strict_types=1);

namespace App\Service\Location\Suggestion;

use App\Entity\Location\CountryProfile;

/**
 * What you get when no AI provider is configured.
 *
 * THROWS RATHER THAN RETURNING AN EMPTY LIST, for the same reason
 * UnconfiguredLocationEnricher does. A suggester that quietly produced nothing would be
 * indistinguishable from a country the model has run out of towns for, and an operator would
 * conclude the catalogue was complete when in fact the key was missing.
 */
final class UnconfiguredTownSuggester implements TownSuggesterInterface
{
    private const DETAIL = 'No AI provider is configured. Set LOCATION_ENRICHER_PROVIDER, '
        . 'LOCATION_ENRICHER_API_KEY and LOCATION_ENRICHER_MODEL.';

    public function suggest(CountryProfile $country, array $excludeNames, int $max): TownSuggestionResult
    {
        throw new TownSuggestionUnavailableException(self::DETAIL);
    }

    public function provider(): string
    {
        return 'unconfigured';
    }

    public function model(): string
    {
        return 'none';
    }

    public function promptVersion(): string
    {
        return 'none';
    }

    public function checkConnection(): array
    {
        return ['ok' => false, 'detail' => self::DETAIL];
    }
}
