<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\AiUnavailableException;
use App\Service\Ai\AnthropicJsonClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The HTTP layer every paid call in this codebase goes through.
 *
 * It had no tests at all until a second feature needed it, which is why it was extracted:
 * auth, the pinned API version and the structured-output request shape were being copied
 * rather than shared. These assert the request we actually send and, just as importantly,
 * that every way of not getting an answer raises rather than returning something empty that
 * a caller would mistake for the model declining.
 *
 * MockHttpClient throughout. The suite must never make a real API call: it would be slow,
 * non-deterministic and billed.
 */
class AnthropicJsonClientTest extends TestCase
{
    private const SCHEMA = [
        'type'                 => 'object',
        'properties'           => ['answer' => ['type' => 'string']],
        'required'             => ['answer'],
        'additionalProperties' => false,
    ];

    public function testItSendsTheKeyTheModelAndThePinnedApiVersion(): void
    {
        $seen = null;
        $client = $this->client(function (string $method, string $url, array $options) use (&$seen) {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return $this->reply(['answer' => 'yes']);
        });

        $client->sendJson('hello', self::SCHEMA, 100, 'a test');

        $this->assertSame('POST', $seen['method']);
        $this->assertSame('https://api.anthropic.com/v1/messages', $seen['url']);
        $this->assertContains('x-api-key: sk-ant-test', $seen['options']['headers']);
        // Pinned, not latest — a server-side default change must not be able to alter parsing.
        $this->assertContains('anthropic-version: 2023-06-01', $seen['options']['headers']);

        $body = json_decode($seen['options']['body'], true);
        $this->assertSame('claude-test', $body['model']);
        $this->assertSame(100, $body['max_tokens']);
        $this->assertSame([['role' => 'user', 'content' => 'hello']], $body['messages']);
    }

    public function testItRequestsStructuredOutputWithTheSchemaItWasGiven(): void
    {
        // The single most important line in the request. Without it the reply is free text that
        // can silently omit a field, which lands as half-data nobody notices.
        $seen = null;
        $client = $this->client(function (string $m, string $u, array $options) use (&$seen) {
            $seen = json_decode($options['body'], true);

            return $this->reply(['answer' => 'yes']);
        });

        $client->sendJson('hello', self::SCHEMA, 100, 'a test');

        $this->assertSame('json_schema', $seen['output_config']['format']['type']);
        $this->assertSame(self::SCHEMA, $seen['output_config']['format']['schema']);
    }

    public function testASchemaFreeCallSendsNoOutputConfig(): void
    {
        // What a connection check uses: one token, no schema, so it fails only for reasons
        // actually to do with connectivity.
        $seen = null;
        $client = $this->client(function (string $m, string $u, array $options) use (&$seen) {
            $seen = json_decode($options['body'], true);

            return $this->reply(['answer' => 'ok']);
        });

        $client->send('ping', 16);

        $this->assertArrayNotHasKey('output_config', $seen);
    }

    public function testItReturnsTheDecodedObjectAndTheTokenCounts(): void
    {
        $client = $this->client(fn () => $this->reply(['answer' => 'forty-two'], inputTokens: 123, outputTokens: 45));

        $response = $client->sendJson('hello', self::SCHEMA, 100, 'a test');

        $this->assertSame(['answer' => 'forty-two'], $response->data);
        $this->assertSame(123, $response->inputTokens);
        $this->assertSame(45, $response->outputTokens);
    }

    public function testMissingTokenCountsAreNullRatherThanZero(): void
    {
        // Zero would be a lie that looks like a measurement; null says "not reported".
        $client = $this->client(fn () => new MockResponse(json_encode([
            'content' => [['type' => 'text', 'text' => json_encode(['answer' => 'x'])]],
        ]), ['http_code' => 200]));

        $response = $client->sendJson('hello', self::SCHEMA, 100, 'a test');

        $this->assertNull($response->inputTokens);
        $this->assertNull($response->outputTokens);
    }

    public function testANonTwoHundredRaisesAndNamesTheStatus(): void
    {
        $client = $this->client(fn () => new MockResponse('{"error":{"message":"overloaded"}}', ['http_code' => 529]));

        $this->expectException(AiUnavailableException::class);
        $this->expectExceptionMessageMatches('/HTTP 529/');

        $client->sendJson('hello', self::SCHEMA, 100, 'a test');
    }

    public function testAHugeErrorBodyIsTruncated(): void
    {
        // The message lands in a varchar(255) column downstream; an untruncated HTML error page
        // would either blow the column or crowd out the status code that identifies the problem.
        $client = $this->client(fn () => new MockResponse(str_repeat('x', 5000), ['http_code' => 500]));

        try {
            $client->sendJson('hello', self::SCHEMA, 100, 'a test');
            $this->fail('Expected AiUnavailableException.');
        } catch (AiUnavailableException $e) {
            $this->assertLessThan(255, \strlen($e->getMessage()));
        }
    }

    public function testABodyThatIsNotJsonRaises(): void
    {
        $client = $this->client(fn () => new MockResponse('<html>gateway</html>', ['http_code' => 200]));

        $this->expectException(AiUnavailableException::class);

        $client->sendJson('hello', self::SCHEMA, 100, 'a test');
    }

    public function testAReplyWithNoTextBlockRaisesAndNamesWhatWasAskedAbout(): void
    {
        // A 200 with an unexpected shape must be a visible error, not a silently empty result
        // that reads like the provider answering unhelpfully. The context is what makes a
        // failure inside a batch traceable.
        // stop_reason is deliberately NOT max_tokens: truncation has its own message now,
        // tested separately, and this case is about an empty reply the model considered
        // finished — which has no remedy an operator could act on, so it has to name the
        // reason it gave.
        $client = $this->client(fn () => new MockResponse(json_encode([
            'content'     => [],
            'stop_reason' => 'end_turn',
        ]), ['http_code' => 200]));

        $this->expectException(AiUnavailableException::class);
        $this->expectExceptionMessageMatches('/town list for Argentina.*end_turn.*blocks: none/');

        $client->sendJson('hello', self::SCHEMA, 100, 'town list for Argentina');
    }

    public function testATextBlockThatIsNotJsonRaises(): void
    {
        $client = $this->client(fn () => new MockResponse(json_encode([
            'content' => [['type' => 'text', 'text' => 'Sure! Here are some towns:']],
        ]), ['http_code' => 200]));

        $this->expectException(AiUnavailableException::class);
        $this->expectExceptionMessageMatches('/not JSON/');

        $client->sendJson('hello', self::SCHEMA, 100, 'a test');
    }

    public function testConfigurationIsReportedRatherThanAssumed(): void
    {
        $configured = new AnthropicJsonClient(new MockHttpClient(), new NullLogger(), 'sk-ant-test', 'claude-test');
        $this->assertTrue($configured->isConfigured());
        $this->assertTrue($configured->hasApiKey());

        $noKey = new AnthropicJsonClient(new MockHttpClient(), new NullLogger(), '', 'claude-test');
        $this->assertFalse($noKey->isConfigured());
        $this->assertFalse($noKey->hasApiKey());

        $noModel = new AnthropicJsonClient(new MockHttpClient(), new NullLogger(), 'sk-ant-test', '');
        $this->assertFalse($noModel->isConfigured());
    }

    /**
     * Regression: a thinking block before the answer was read as "no answer".
     *
     * This is the bug that broke the first real US town pull. sendJson() indexed content[0],
     * and on adaptive-thinking models — Claude Sonnet 5 among them — thinking is on by default
     * and its block comes FIRST, so a perfectly good 200 with the JSON sitting at content[1]
     * was reported as the provider returning nothing. The structured-outputs docs say to
     * iterate the content array rather than index it.
     *
     * It hid for a while because adaptive thinking is per-request: the enricher's five scalars
     * about one town are easy enough that the model skips thinking, so that path kept working
     * while a fifty-town list did not.
     */
    public function testTheAnswerIsFoundAfterAThinkingBlock(): void
    {
        $client = $this->client(fn () => new MockResponse(json_encode([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Let me recall some towns...'],
                ['type' => 'text', 'text' => json_encode(['answer' => 'forty-two'])],
            ],
            'stop_reason' => 'end_turn',
        ]), ['http_code' => 200]));

        $response = $client->sendJson('hello', self::SCHEMA, 100, 'a test');

        $this->assertSame(['answer' => 'forty-two'], $response->data);
    }

    public function testTheAnswerIsFoundPastSeveralNonTextBlocks(): void
    {
        // Nothing guarantees how many non-text blocks precede the answer, so position is not
        // assumed at all — only the type is.
        $client = $this->client(fn () => new MockResponse(json_encode([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'first'],
                ['type' => 'redacted_thinking', 'data' => 'opaque'],
                ['type' => 'text', 'text' => json_encode(['answer' => 'found'])],
            ],
        ]), ['http_code' => 200]));

        $this->assertSame(['answer' => 'found'], $client->sendJson('hello', self::SCHEMA, 100, 'a test')->data);
    }

    public function testTruncationSaysSoAndNamesTheCeiling(): void
    {
        // Distinct from a transient fault, because the remedy is distinct: an identical retry
        // cannot help. The handler burned three billed calls on this before it said anything
        // useful, and the message it did produce blamed the wrong thing.
        $client = $this->client(fn () => new MockResponse(json_encode([
            'content'     => [['type' => 'thinking', 'thinking' => 'thought until the budget ran out']],
            'stop_reason' => 'max_tokens',
        ]), ['http_code' => 200]));

        try {
            $client->sendJson('hello', self::SCHEMA, 2048, 'town list for USA');
            $this->fail('Expected AiUnavailableException.');
        } catch (AiUnavailableException $e) {
            $this->assertStringContainsString('2048-token ceiling', $e->getMessage());
            $this->assertStringContainsString('town list for USA', $e->getMessage());
            $this->assertStringNotContainsString('no text block', $e->getMessage());
        }
    }

    public function testJsonCutOffMidReplyIsReportedAsTruncationNotAsBadJson(): void
    {
        // The likelier shape of a truncated list: a text block holding JSON that simply stops.
        // "not JSON" would send an operator looking at the prompt instead of the budget.
        $client = $this->client(fn () => new MockResponse(json_encode([
            'content'     => [['type' => 'text', 'text' => '{"towns":[{"name":"Akron","popul']],
            'stop_reason' => 'max_tokens',
        ]), ['http_code' => 200]));

        try {
            $client->sendJson('hello', self::SCHEMA, 6144, 'town list for USA');
            $this->fail('Expected AiUnavailableException.');
        } catch (AiUnavailableException $e) {
            $this->assertStringContainsString('cut off', $e->getMessage());
            $this->assertStringContainsString('6144-token ceiling', $e->getMessage());
        }
    }

    public function testAnUnusableReplyNamesTheBlockTypesItDidGetBack(): void
    {
        // The original error said only "no text block", which was true and useless. The types
        // are what identify the shape problem — and only the types: a thinking block's content
        // is the model's reasoning and has no business in our logs.
        $client = $this->client(fn () => new MockResponse(json_encode([
            'content'     => [['type' => 'thinking', 'thinking' => 'secret reasoning']],
            'stop_reason' => 'end_turn',
        ]), ['http_code' => 200]));

        try {
            $client->sendJson('hello', self::SCHEMA, 100, 'town list for USA');
            $this->fail('Expected AiUnavailableException.');
        } catch (AiUnavailableException $e) {
            $this->assertStringContainsString('blocks: thinking', $e->getMessage());
            $this->assertStringNotContainsString('secret reasoning', $e->getMessage());
        }
    }

    public function testATextBlockWithNoTextIsSkippedRatherThanTrusted(): void
    {
        $client = $this->client(fn () => new MockResponse(json_encode([
            'content' => [
                ['type' => 'text'],
                ['type' => 'text', 'text' => json_encode(['answer' => 'second'])],
            ],
        ]), ['http_code' => 200]));

        $this->assertSame(['answer' => 'second'], $client->sendJson('hello', self::SCHEMA, 100, 'a test')->data);
    }

    private function client(callable $handler): AnthropicJsonClient
    {
        return new AnthropicJsonClient(new MockHttpClient($handler), new NullLogger(), 'sk-ant-test', 'claude-test');
    }

    /** @param array<string, mixed> $payload */
    private function reply(array $payload, ?int $inputTokens = null, ?int $outputTokens = null): MockResponse
    {
        return new MockResponse(json_encode([
            'content' => [['type' => 'text', 'text' => json_encode($payload)]],
            'usage'   => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ]), ['http_code' => 200]);
    }
}
