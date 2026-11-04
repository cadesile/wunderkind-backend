<?php

declare(strict_types=1);

namespace App\Service\Location\Enrichment;

use Psr\Log\LoggerInterface;

/**
 * Picks the enricher implementation from LOCATION_ENRICHER_PROVIDER.
 *
 * WHY A FACTORY AND NOT AN ALIAS. A Symfony service alias needs a concrete service id at
 * compile time — `alias: '%env(...)%'` is not resolvable and fails container compilation. So
 * selecting a provider by environment variable, which is the whole point (a model or provider
 * change should be a deploy variable, not a code change), has to happen in a factory.
 *
 * FALLS BACK TO REFUSING LOUDLY, never to doing nothing. An unrecognised provider, a missing
 * key, or a missing model all yield UnconfiguredLocationEnricher, which throws
 * EnrichmentUnavailableException and reports not-ok to the admin connection check. The
 * alternative — an enricher that quietly returns nothing — would look exactly like a provider
 * that answered unhelpfully, and 865 rows would land in FAILED with no indication why. That is
 * not hypothetical: a missing FIREBASE_SERVICE_ACCOUNT_JSON once discarded every push
 * notification in this codebase with zero trace.
 *
 * A MISCONFIGURATION IS LOGGED AT WARNING. Setting the provider to `antropic` and getting
 * silence would be its own small incident, so a provider that is set but unrecognised, or
 * recognised but missing credentials, says so in the log at boot-adjacent time rather than
 * only when a sweep starts.
 */
final class LocationEnricherFactory
{
    /** Accepted values of LOCATION_ENRICHER_PROVIDER for the Claude implementation. */
    private const ANTHROPIC = ['anthropic', 'claude'];

    /** The value meaning "deliberately off". Anything unrecognised is treated the same, but logged. */
    private const NONE = ['', 'none', 'unconfigured'];

    public function __construct(
        private readonly ClaudeLocationEnricher       $claude,
        private readonly UnconfiguredLocationEnricher $unconfigured,
        private readonly LoggerInterface              $logger,
        private readonly string                       $provider,
        private readonly string                       $apiKey,
        private readonly string                       $model,
    ) {}

    public function create(): LocationEnricherInterface
    {
        $provider = strtolower(trim($this->provider));

        if (in_array($provider, self::NONE, true)) {
            return $this->unconfigured;
        }

        if (!in_array($provider, self::ANTHROPIC, true)) {
            $this->logger->warning(
                'LOCATION_ENRICHER_PROVIDER is set to an unrecognised value; enrichment stays off.',
                ['provider' => $this->provider, 'recognised' => self::ANTHROPIC],
            );

            return $this->unconfigured;
        }

        // Selected, but unusable. Distinguished in the log from "not selected", because the two
        // need different fixes and an operator who set the provider expects it to work.
        if (trim($this->apiKey) === '' || trim($this->model) === '') {
            $this->logger->warning(
                'LOCATION_ENRICHER_PROVIDER selects Claude but the key or model is empty; enrichment stays off.',
                ['hasKey' => trim($this->apiKey) !== '', 'model' => $this->model],
            );

            return $this->unconfigured;
        }

        return $this->claude;
    }
}
