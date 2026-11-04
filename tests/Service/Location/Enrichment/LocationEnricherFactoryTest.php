<?php

declare(strict_types=1);

namespace App\Tests\Service\Location\Enrichment;

use App\Service\Location\Enrichment\ClaudeLocationEnricher;
use App\Service\Location\Enrichment\LocationEnricherFactory;
use App\Service\Location\Enrichment\UnconfiguredLocationEnricher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Which enricher the provider variable selects.
 *
 * Every case here asserts the same underlying property: **a misconfiguration falls back to
 * refusing loudly, never to doing nothing.** An enricher that quietly returned nothing would be
 * indistinguishable from a provider answering unhelpfully, and an 865-row sweep would land in
 * FAILED with no indication why — which is precisely what happened in this codebase once with a
 * missing FIREBASE_SERVICE_ACCOUNT_JSON.
 *
 * No network: ClaudeLocationEnricher is constructed over a MockHttpClient and never called.
 */
class LocationEnricherFactoryTest extends TestCase
{
    public function testAnthropicWithAKeyAndModelSelectsClaude(): void
    {
        $this->assertInstanceOf(
            ClaudeLocationEnricher::class,
            $this->factory('anthropic', 'sk-ant-test', 'claude-sonnet-5')->create(),
        );
    }

    public function testClaudeIsAlsoAcceptedAsTheProviderName(): void
    {
        // Operators will type either. Accepting one and silently ignoring the other is the kind
        // of thing that costs an afternoon.
        $this->assertInstanceOf(
            ClaudeLocationEnricher::class,
            $this->factory('claude', 'sk-ant-test', 'claude-sonnet-5')->create(),
        );
    }

    public function testProviderMatchingIsCaseAndWhitespaceInsensitive(): void
    {
        $this->assertInstanceOf(
            ClaudeLocationEnricher::class,
            $this->factory('  Anthropic  ', 'sk-ant-test', 'claude-sonnet-5')->create(),
        );
    }

    public function testAnEmptyProviderIsTheDefaultAndStaysOff(): void
    {
        $this->assertInstanceOf(
            UnconfiguredLocationEnricher::class,
            $this->factory('', 'sk-ant-test', 'claude-sonnet-5')->create(),
        );
    }

    public function testAnUnrecognisedProviderFallsBackRatherThanFailing(): void
    {
        // A typo — `antropic` — must not boot a half-configured enricher, and must not take the
        // container down either.
        $this->assertInstanceOf(
            UnconfiguredLocationEnricher::class,
            $this->factory('antropic', 'sk-ant-test', 'claude-sonnet-5')->create(),
        );
    }

    public function testAProviderWithNoKeyStaysOff(): void
    {
        $this->assertInstanceOf(
            UnconfiguredLocationEnricher::class,
            $this->factory('anthropic', '', 'claude-sonnet-5')->create(),
        );
    }

    public function testAProviderWithNoModelStaysOff(): void
    {
        $this->assertInstanceOf(
            UnconfiguredLocationEnricher::class,
            $this->factory('anthropic', 'sk-ant-test', '')->create(),
        );
    }

    public function testAWhitespaceOnlyKeyCountsAsAbsent(): void
    {
        // A deploy secret set to a blank string is a real and easy mistake, and it must not
        // reach the API as an empty x-api-key header.
        $this->assertInstanceOf(
            UnconfiguredLocationEnricher::class,
            $this->factory('anthropic', '   ', 'claude-sonnet-5')->create(),
        );
    }

    public function testTheUnconfiguredMessageTellsTheOperatorOnlyThingsTheyCanDo(): void
    {
        $message = UnconfiguredLocationEnricher::MESSAGE;

        $this->assertStringContainsString('LOCATION_ENRICHER_PROVIDER', $message);
        $this->assertStringContainsString('LOCATION_ENRICHER_MODEL', $message);
        $this->assertStringContainsString('LOCATION_ENRICHER_API_KEY', $message);

        // It used to tell operators to re-alias a service, which was a code change. Selecting a
        // provider is a deploy variable now, and the message must not send anyone into
        // services.yaml.
        $this->assertStringNotContainsString('alias', $message);
    }

    private function factory(string $provider, string $apiKey, string $model): LocationEnricherFactory
    {
        return new LocationEnricherFactory(
            new ClaudeLocationEnricher(new MockHttpClient(), new NullLogger(), $apiKey, $model),
            new UnconfiguredLocationEnricher(),
            new NullLogger(),
            $provider,
            $apiKey,
            $model,
        );
    }
}
