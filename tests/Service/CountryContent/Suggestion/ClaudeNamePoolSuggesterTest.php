<?php

declare(strict_types=1);

namespace App\Tests\Service\CountryContent\Suggestion;

use App\Entity\Location\CountryProfile;
use App\Service\Ai\AnthropicJsonClient;
use App\Service\CountryContent\NamePoolValidator;
use App\Service\CountryContent\Suggestion\ClaudeNamePoolSuggester;
use App\Service\CountryContent\Suggestion\NamePoolUnavailableException;
use App\Service\CountryContent\Suggestion\UnconfiguredNamePoolSuggester;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What we ask for, and what we do with the answer.
 *
 * MockHttpClient throughout — the suite must never make a real, billed API call.
 */
class ClaudeNamePoolSuggesterTest extends TestCase
{
    public function testItParsesTheList(): void
    {
        $suggester = $this->suggester(fn () => $this->reply(['Ardit', 'Bledar', 'Gentian']));

        $result = $suggester->suggest($this->albania(), 'firstNames', [], 150);

        self::assertSame(['Ardit', 'Bledar', 'Gentian'], $result->names);
        self::assertSame(10, $result->inputTokens);
        self::assertSame(20, $result->outputTokens);
    }

    public function testBlanksAndNonStringsAreDiscardedAndNamesAreTrimmed(): void
    {
        $suggester = $this->suggester(fn () => $this->reply(['Ardit', '', '   ', 42, null, ' Bledar ']));

        $result = $suggester->suggest($this->albania(), 'firstNames', [], 150);

        self::assertSame(['Ardit', 'Bledar'], $result->names);
    }

    /**
     * Diacritics must survive the whole round trip.
     *
     * This is the single property the feature exists for — an anglicised pool is the pool the
     * game already ships. A JSON encode/decode cycle that mangled them would be invisible
     * until an operator read a Turkish squad list.
     */
    public function testLocalOrthographySurvivesTheRoundTrip(): void
    {
        $suggester = $this->suggester(fn () => $this->reply(['Güneş', 'Yıldırım', 'Łukasz', 'Þórir']));

        $result = $suggester->suggest($this->albania(), 'lastNames', [], 150);

        self::assertSame(['Güneş', 'Yıldırım', 'Łukasz', 'Þórir'], $result->names);
    }

    public function testTheRequestCarriesTheSchema(): void
    {
        $body      = null;
        $suggester = $this->suggester(function (string $m, string $u, array $options) use (&$body) {
            $body = json_decode($options['body'], true);

            return $this->reply([]);
        });

        $suggester->suggest($this->albania(), 'firstNames', [], 150);

        $schema = $body['output_config']['format']['schema'];

        self::assertSame('json_schema', $body['output_config']['format']['type']);
        self::assertSame(['names'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame('array', $schema['properties']['names']['type']);
        self::assertSame('string', $schema['properties']['names']['items']['type']);
        // Recall against a fixed schema, not reasoning — and thinking tokens come out of the
        // same budget as the answer, which is what truncated the town batches.
        self::assertSame(AnthropicJsonClient::EFFORT_LOW, $body['output_config']['effort']);
    }

    /**
     * The two lists must be unmistakably different asks.
     *
     * Asked vaguely for "names", a model hands back a mix of given and family names, and the
     * surname pool quietly fills with first names — a corruption nothing downstream can
     * detect, because both are just strings.
     */
    public function testThePromptSaysWhichOfTheTwoListsItWants(): void
    {
        $first = $this->promptFor('firstNames');
        $last  = $this->promptFor('lastNames');

        self::assertStringContainsString('GIVEN names', $first);
        self::assertStringNotContainsString('FAMILY names', $first);

        self::assertStringContainsString('FAMILY names', $last);
        self::assertStringContainsString('surnames', $last);
    }

    /** The anglicised answer is the failure that matters, so the prompt must forbid it by name. */
    public function testThePromptNamesTheCountryAndForbidsTheAnglicisedAnswer(): void
    {
        $prompt = $this->promptFor('firstNames');

        self::assertStringContainsString('Albania', $prompt);
        self::assertStringContainsString('diacritics intact', $prompt);
        self::assertStringContainsString('Do NOT anglicise', $prompt);
    }

    /** Without the exclusions the model returns what we hold and the call pays for nothing. */
    public function testWhatWeAlreadyHoldGoesInThePromptAsExclusions(): void
    {
        $prompt = $this->promptFor('firstNames', ['Ardit', 'Bledar']);

        self::assertStringContainsString('do NOT return any of them', $prompt);
        self::assertStringContainsString('Ardit, Bledar', $prompt);
    }

    /** A first list must not claim to be extending one. */
    public function testAnEmptyPoolIsAskedForTheWholeListRatherThanFurtherNames(): void
    {
        $prompt = $this->promptFor('firstNames', []);

        self::assertStringContainsString('We hold none', $prompt);
        self::assertStringNotContainsString('do NOT return any of them', $prompt);
    }

    /**
     * A huge exclusion list is truncated, and says so.
     *
     * Sending a thousand names would cost more in input tokens than the answer is worth, and
     * would crowd out the instruction. The server-side dedupe is what guarantees correctness.
     */
    public function testAnOversizedExclusionListIsTruncatedAndTheTotalIsStated(): void
    {
        $held = [];

        for ($i = 1; $i <= 500; $i++) {
            $held[] = 'Held' . $i;
        }

        $prompt = $this->promptFor('lastNames', $held);

        self::assertStringContainsString('we hold 500 in total', $prompt);
        self::assertStringNotContainsString('Held500', $prompt);
    }

    /** The validator's ceiling is the ask's ceiling; a silly target must not become a silly bill. */
    public function testTheAskIsCappedAtTheValidatorsMaximum(): void
    {
        $prompt = $this->promptFor('firstNames', [], 10_000);

        self::assertStringContainsString(sprintf('up to %d further', NamePoolValidator::MAX_PER_LIST), $prompt);
    }

    public function testATransportFailureThrowsRatherThanReturningNothing(): void
    {
        $suggester = $this->suggester(fn () => new MockResponse('nope', ['http_code' => 500]));

        $this->expectException(NamePoolUnavailableException::class);

        $suggester->suggest($this->albania(), 'firstNames', [], 150);
    }

    public function testTheUnconfiguredSuggesterRefusesLoudly(): void
    {
        $unconfigured = new UnconfiguredNamePoolSuggester();

        self::assertFalse($unconfigured->isConfigured());

        $this->expectException(NamePoolUnavailableException::class);
        $unconfigured->suggest($this->albania(), 'firstNames', [], 150);
    }

    public function testAMissingKeyIsRefusedBeforeAnythingIsSent(): void
    {
        $suggester = new ClaudeNamePoolSuggester(
            new AnthropicJsonClient(
                new MockHttpClient(static fn () => new MockResponse('', ['http_code' => 500])),
                new NullLogger(),
                '',
                'claude-test',
            ),
        );

        self::assertFalse($suggester->isConfigured());

        $this->expectException(NamePoolUnavailableException::class);
        $suggester->suggest($this->albania(), 'firstNames', [], 150);
    }

    /** @param list<string> $exclude */
    private function promptFor(string $list, array $exclude = [], int $want = 150): string
    {
        $prompt    = null;
        $suggester = $this->suggester(function (string $m, string $u, array $options) use (&$prompt) {
            $prompt = json_decode($options['body'], true)['messages'][0]['content'];

            return $this->reply([]);
        });

        $suggester->suggest($this->albania(), $list, $exclude, $want);

        return $prompt;
    }

    private function suggester(callable $handler): ClaudeNamePoolSuggester
    {
        return new ClaudeNamePoolSuggester(
            new AnthropicJsonClient(new MockHttpClient($handler), new NullLogger(), 'sk-ant-test', 'claude-test'),
        );
    }

    /** @param list<mixed> $names */
    private function reply(array $names): MockResponse
    {
        return new MockResponse(json_encode([
            'content' => [['type' => 'text', 'text' => json_encode(['names' => $names])]],
            'usage'   => ['input_tokens' => 10, 'output_tokens' => 20],
        ]), ['http_code' => 200]);
    }

    private function albania(): CountryProfile
    {
        return new CountryProfile('AL', 'Albania');
    }
}
