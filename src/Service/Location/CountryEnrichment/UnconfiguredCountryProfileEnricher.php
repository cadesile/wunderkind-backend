<?php

declare(strict_types=1);

namespace App\Service\Location\CountryEnrichment;

use App\Entity\Location\CountryProfile;

/**
 * The default enricher: refuses loudly, because no provider has been chosen.
 *
 * Exists for the same reason UnconfiguredLocationEnricher does — so "no provider configured"
 * is a VISIBLE state. Leaving the interface unimplemented breaks container compilation and
 * takes the whole admin down; an enricher that returned an empty answer would look exactly
 * like a provider that had nothing to say, and the operator would be left with an unchanged
 * country row and a success message.
 *
 * It shares the location enricher's three environment variables, so its message is the same
 * one: there is one provider, one key and one model for everything in this feature, and an
 * operator who has configured enrichment has configured this too.
 */
final class UnconfiguredCountryProfileEnricher implements CountryProfileEnricherInterface
{
    public const MESSAGE = 'No AI enricher is configured. '
        . "Set LOCATION_ENRICHER_PROVIDER='anthropic', LOCATION_ENRICHER_MODEL "
        . '(e.g. claude-sonnet-5) and LOCATION_ENRICHER_API_KEY — in .env.local for local work, '
        . 'or as deploy secrets. No code change is needed.';

    public function enrich(CountryProfile $country): CountryProfileFacts
    {
        throw new CountryEnrichmentUnavailableException(self::MESSAGE);
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
