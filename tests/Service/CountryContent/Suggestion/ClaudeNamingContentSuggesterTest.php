<?php

declare(strict_types=1);

namespace App\Tests\Service\CountryContent\Suggestion;

use App\Entity\Location\CountryProfile;
use App\Service\Ai\AnthropicJsonClient;
use App\Service\CountryContent\Suggestion\ClaudeNamingContentSuggester;
use App\Service\CountryContent\Suggestion\NamingContentUnavailableException;
use App\Service\CountryContent\Suggestion\UnconfiguredNamingContentSuggester;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What we ask for, and what we do with the answer.
 *
 * MockHttpClient throughout — the suite must never make a real, billed API call.
 */
class ClaudeNamingContentSuggesterTest extends TestCase
{
    public function testItParsesTheThreeLists(): void
    {
        $suggester = $this->suggester(fn () => $this->reply([
            'prestigeSuffixes' => ['Legia', 'Wisła'],
            'genericSuffixes'  => ['Stal', 'Odra'],
            'stadiumFormats'   => ['Stadion %s'],
        ]));

        $result = $suggester->suggest($this->poland());

        self::assertSame(['Legia', 'Wisła'], $result->prestigeSuffixes);
        self::assertSame(['Stal', 'Odra'], $result->genericSuffixes);
        self::assertSame(['Stadion %s'], $result->stadiumFormats);
        self::assertSame(10, $result->inputTokens);
    }

    public function testBlanksAndNonStringsAreDiscardedRatherThanStored(): void
    {
        $suggester = $this->suggester(fn () => $this->reply([
            'prestigeSuffixes' => ['Legia', '', '   ', 42, null, 'Wisła'],
            'genericSuffixes'  => 'not a list',
            'stadiumFormats'   => [],
        ]));

        $result = $suggester->suggest($this->poland());

        self::assertSame(['Legia', 'Wisła'], $result->prestigeSuffixes);
        self::assertSame([], $result->genericSuffixes);
    }

    /**
     * The schema is the single most important line in the request.
     *
     * All three keys required, additionalProperties closed, and the %s rule stated in the
     * stadium description — so the model cannot silently drop a list or smuggle in a fourth.
     */
    public function testTheRequestCarriesTheSchema(): void
    {
        $body = null;
        $suggester = $this->suggester(function (string $m, string $u, array $options) use (&$body) {
            $body = json_decode($options['body'], true);

            return $this->reply([]);
        });

        $suggester->suggest($this->poland());

        $schema = $body['output_config']['format']['schema'];

        self::assertSame('json_schema', $body['output_config']['format']['type']);
        self::assertSame(['prestigeSuffixes', 'genericSuffixes', 'stadiumFormats'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
        self::assertStringContainsString('%s', $schema['properties']['stadiumFormats']['description']);
        // Recall against a fixed schema, not reasoning — and thinking tokens come out of the
        // same budget as the answer.
        self::assertSame(AnthropicJsonClient::EFFORT_LOW, $body['output_config']['effort']);
    }

    /**
     * The prompt has to fight the generic answer, which is the one failure that matters here.
     *
     * A model asked for "club name suffixes" will offer United, City and FC, and a Polish
     * league full of those reads like a translation.
     */
    public function testThePromptNamesTheCountryAndForbidsTheGenericAnswer(): void
    {
        $prompt = null;
        $suggester = $this->suggester(function (string $m, string $u, array $options) use (&$prompt) {
            $prompt = json_decode($options['body'], true)['messages'][0]['content'];

            return $this->reply([]);
        });

        $suggester->suggest($this->poland());

        self::assertStringContainsString('Poland', $prompt);
        self::assertStringContainsString('United, City or FC', $prompt);
        self::assertStringContainsString('%s exactly', $prompt);
        // The shape example must say, out loud, not to copy its words.
        self::assertStringContainsString('Do not reuse any of', $prompt);
    }

    public function testATransportFailureThrowsRatherThanReturningNothing(): void
    {
        $suggester = $this->suggester(fn () => new MockResponse('nope', ['http_code' => 500]));

        $this->expectException(NamingContentUnavailableException::class);

        $suggester->suggest($this->poland());
    }

    public function testTheUnconfiguredSuggesterRefusesLoudly(): void
    {
        $unconfigured = new UnconfiguredNamingContentSuggester();

        self::assertFalse($unconfigured->isConfigured());

        $this->expectException(NamingContentUnavailableException::class);
        $unconfigured->suggest($this->poland());
    }

    public function testAMissingKeyIsRefusedBeforeAnythingIsSent(): void
    {
        $suggester = new ClaudeNamingContentSuggester(
            new AnthropicJsonClient(new MockHttpClient(static fn () => new MockResponse('', ['http_code' => 500])), new NullLogger(), '', 'claude-test'),
        );

        self::assertFalse($suggester->isConfigured());

        $this->expectException(NamingContentUnavailableException::class);
        $suggester->suggest($this->poland());
    }

    private function suggester(callable $handler): ClaudeNamingContentSuggester
    {
        return new ClaudeNamingContentSuggester(
            new AnthropicJsonClient(new MockHttpClient($handler), new NullLogger(), 'sk-ant-test', 'claude-test'),
        );
    }

    /** @param array<string, mixed> $lists */
    private function reply(array $lists): MockResponse
    {
        return new MockResponse(json_encode([
            'content' => [['type' => 'text', 'text' => json_encode($lists)]],
            'usage'   => ['input_tokens' => 10, 'output_tokens' => 20],
        ]), ['http_code' => 200]);
    }

    private function poland(): CountryProfile
    {
        return new CountryProfile('PL', 'Poland');
    }
}
