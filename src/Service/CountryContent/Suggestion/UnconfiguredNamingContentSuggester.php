<?php

declare(strict_types=1);

namespace App\Service\CountryContent\Suggestion;

use App\Entity\Location\CountryProfile;

/**
 * The default suggester: refuses loudly, because no provider has been chosen.
 *
 * Exists so "no provider configured" is a visible state rather than a silent one — the same
 * reason UnconfiguredLocationEnricher and UnconfiguredTownSuggester do. A suggester that
 * returned nothing would look exactly like a model with nothing to offer, and the operator
 * would be left reading an empty form and a success message.
 */
final class UnconfiguredNamingContentSuggester implements NamingContentSuggesterInterface
{
    public const MESSAGE = 'No AI provider is configured. '
        . "Set LOCATION_ENRICHER_PROVIDER='anthropic', LOCATION_ENRICHER_MODEL "
        . '(e.g. claude-sonnet-5) and LOCATION_ENRICHER_API_KEY — in .env.local for local work, '
        . 'or as deploy secrets. No code change is needed.';

    public function suggest(CountryProfile $country): NamingContentSuggestion
    {
        throw new NamingContentUnavailableException(self::MESSAGE);
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
        return 'v0';
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
