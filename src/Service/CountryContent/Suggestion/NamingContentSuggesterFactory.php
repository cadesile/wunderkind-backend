<?php

declare(strict_types=1);

namespace App\Service\CountryContent\Suggestion;

use Psr\Log\LoggerInterface;

/**
 * Picks the naming-content suggester from LOCATION_ENRICHER_PROVIDER.
 *
 * A factory and not an alias, for the reason LocationEnricherFactory's docblock sets out at
 * length: `alias: '%env(...)%'` does not compile. Shares that feature's three variables rather
 * than inventing a fourth set — one provider, one key, one model.
 */
final class NamingContentSuggesterFactory
{
    private const ANTHROPIC = ['anthropic', 'claude'];

    private const NONE = ['', 'none', 'unconfigured'];

    public function __construct(
        private readonly ClaudeNamingContentSuggester       $claude,
        private readonly UnconfiguredNamingContentSuggester $unconfigured,
        private readonly LoggerInterface                    $logger,
        private readonly string                             $provider,
        private readonly string                             $apiKey,
        private readonly string                             $model,
    ) {}

    public function create(): NamingContentSuggesterInterface
    {
        $provider = strtolower(trim($this->provider));

        if (\in_array($provider, self::NONE, true)) {
            return $this->unconfigured;
        }

        if (!\in_array($provider, self::ANTHROPIC, true)) {
            $this->logger->warning(
                'LOCATION_ENRICHER_PROVIDER is set to an unrecognised value; naming-content suggestion stays off.',
                ['provider' => $this->provider, 'recognised' => self::ANTHROPIC],
            );

            return $this->unconfigured;
        }

        if (trim($this->apiKey) === '' || trim($this->model) === '') {
            $this->logger->warning(
                'LOCATION_ENRICHER_PROVIDER selects Claude but the key or model is empty; naming-content suggestion stays off.',
                ['hasKey' => trim($this->apiKey) !== '', 'model' => $this->model],
            );

            return $this->unconfigured;
        }

        return $this->claude;
    }
}
