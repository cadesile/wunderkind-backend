<?php

declare(strict_types=1);

namespace App\Service\CountryContent\Suggestion;

use Psr\Log\LoggerInterface;

/**
 * Picks the name-pool suggester from LOCATION_ENRICHER_PROVIDER.
 *
 * A factory and not an alias, because `alias: '%env(...)%'` does not compile. Shares the same
 * three variables as the other three AI features here — one provider, one key.
 */
final class NamePoolSuggesterFactory
{
    private const ANTHROPIC = ['anthropic', 'claude'];

    private const NONE = ['', 'none', 'unconfigured'];

    public function __construct(
        private readonly ClaudeNamePoolSuggester       $claude,
        private readonly UnconfiguredNamePoolSuggester $unconfigured,
        private readonly LoggerInterface               $logger,
        private readonly string                        $provider,
        private readonly string                        $apiKey,
        private readonly string                        $model,
    ) {}

    public function create(): NamePoolSuggesterInterface
    {
        $provider = strtolower(trim($this->provider));

        if (\in_array($provider, self::NONE, true)) {
            return $this->unconfigured;
        }

        if (!\in_array($provider, self::ANTHROPIC, true)) {
            $this->logger->warning(
                'LOCATION_ENRICHER_PROVIDER is set to an unrecognised value; name-pool suggestion stays off.',
                ['provider' => $this->provider, 'recognised' => self::ANTHROPIC],
            );

            return $this->unconfigured;
        }

        if (trim($this->apiKey) === '' || trim($this->model) === '') {
            $this->logger->warning(
                'LOCATION_ENRICHER_PROVIDER selects Claude but the key or model is empty; name-pool suggestion stays off.',
                ['hasKey' => trim($this->apiKey) !== '', 'model' => $this->model],
            );

            return $this->unconfigured;
        }

        return $this->claude;
    }
}
