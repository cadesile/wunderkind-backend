<?php

declare(strict_types=1);

namespace App\Service\Location\CountryEnrichment;

use Psr\Log\LoggerInterface;

/**
 * Picks the country enricher from LOCATION_ENRICHER_PROVIDER.
 *
 * A FACTORY AND NOT AN ALIAS, for the reason LocationEnricherFactory's docblock records in
 * full: a service alias needs a concrete id at compile time, so `alias: '%env(...)%'` does
 * not compile, and selecting a provider by environment variable has to happen here.
 *
 * SHARES THE LOCATION ENRICHER'S VARIABLES rather than inventing its own trio. One provider,
 * one key, one model: an operator who configured town enrichment has configured this, and a
 * second set of variables would be three more things to get wrong for no gain.
 *
 * Falls back to refusing loudly, never to doing nothing, and says in the log which of
 * "not selected" and "selected but unusable" it was — those need different fixes.
 */
final class CountryProfileEnricherFactory
{
    private const ANTHROPIC = ['anthropic', 'claude'];

    private const NONE = ['', 'none', 'unconfigured'];

    public function __construct(
        private readonly ClaudeCountryProfileEnricher       $claude,
        private readonly UnconfiguredCountryProfileEnricher $unconfigured,
        private readonly LoggerInterface                    $logger,
        private readonly string                             $provider,
        private readonly string                             $apiKey,
        private readonly string                             $model,
    ) {}

    public function create(): CountryProfileEnricherInterface
    {
        $provider = strtolower(trim($this->provider));

        if (\in_array($provider, self::NONE, true)) {
            return $this->unconfigured;
        }

        if (!\in_array($provider, self::ANTHROPIC, true)) {
            $this->logger->warning(
                'LOCATION_ENRICHER_PROVIDER is set to an unrecognised value; country enrichment stays off.',
                ['provider' => $this->provider, 'recognised' => self::ANTHROPIC],
            );

            return $this->unconfigured;
        }

        if (trim($this->apiKey) === '' || trim($this->model) === '') {
            $this->logger->warning(
                'LOCATION_ENRICHER_PROVIDER selects Claude but the key or model is empty; country enrichment stays off.',
                ['hasKey' => trim($this->apiKey) !== '', 'model' => $this->model],
            );

            return $this->unconfigured;
        }

        return $this->claude;
    }
}
