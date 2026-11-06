<?php

declare(strict_types=1);

namespace App\Service\Location\Suggestion;

use Psr\Log\LoggerInterface;

/**
 * Picks the suggester implementation from LOCATION_ENRICHER_PROVIDER.
 *
 * Same env trio, same selection rules and same loud fallback as LocationEnricherFactory —
 * deliberately, because the two features share a provider and a key, and an operator who
 * configures one has configured both. A separate TOWN_SUGGESTER_PROVIDER would be a second
 * thing to set correctly and a second thing to get wrong, for no benefit anyone asked for.
 *
 * A FACTORY AND NOT AN ALIAS for the same compile-time reason: `alias: '%env(...)%'` does not
 * resolve, so selecting an implementation by environment variable has to happen in PHP.
 */
final class TownSuggesterFactory
{
    /** Accepted values of LOCATION_ENRICHER_PROVIDER for the Claude implementation. */
    private const ANTHROPIC = ['anthropic', 'claude'];

    /** The value meaning "deliberately off". Anything unrecognised is treated the same, but logged. */
    private const NONE = ['', 'none', 'unconfigured'];

    public function __construct(
        private readonly ClaudeTownSuggester $claude,
        private readonly UnconfiguredTownSuggester $unconfigured,
        private readonly LoggerInterface $logger,
        private readonly string $provider,
        private readonly string $apiKey,
        private readonly string $model,
    ) {}

    public function create(): TownSuggesterInterface
    {
        $provider = strtolower(trim($this->provider));

        if (\in_array($provider, self::NONE, true)) {
            return $this->unconfigured;
        }

        if (!\in_array($provider, self::ANTHROPIC, true)) {
            $this->logger->warning(
                'LOCATION_ENRICHER_PROVIDER is set to an unrecognised value; town suggestion stays off.',
                ['provider' => $this->provider, 'recognised' => self::ANTHROPIC],
            );

            return $this->unconfigured;
        }

        // Selected, but unusable. Distinguished in the log from "not selected", because the two
        // need different fixes and an operator who set the provider expects it to work.
        if (trim($this->apiKey) === '' || trim($this->model) === '') {
            $this->logger->warning(
                'LOCATION_ENRICHER_PROVIDER selects Claude but the key or model is empty; town suggestion stays off.',
                ['hasKey' => trim($this->apiKey) !== '', 'model' => $this->model],
            );

            return $this->unconfigured;
        }

        return $this->claude;
    }
}
