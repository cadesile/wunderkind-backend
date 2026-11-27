<?php

declare(strict_types=1);

namespace App\Tests\Service\PersonalAssistant;

use App\Service\Ai\AnthropicJsonClient;
use App\Service\PersonalAssistant\ClaudePaAdviceGenerator;
use App\Service\PersonalAssistant\PaAdviceUnavailableException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The real generator over a mocked HTTP client: what goes on the wire, and how failures map. */
final class ClaudePaAdviceGeneratorTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $sentBody = [];

    private function generator(MockResponse|\Closure $response, string $key = 'sk-test'): ClaudePaAdviceGenerator
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response) {
            $this->sentBody = json_decode((string) $options['body'], true);

            return $response instanceof \Closure ? $response() : $response;
        });

        return new ClaudePaAdviceGenerator(new AnthropicJsonClient($http, new NullLogger(), $key, 'claude-haiku-5-5'));
    }

    private static function reply(string $message): MockResponse
    {
        return new MockResponse((string) json_encode([
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => json_encode(['message' => $message])]],
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
        ]), ['http_code' => 200]);
    }

    public function testTheSystemPromptIsSentSeparatelyAtLowEffort(): void
    {
        $text = $this->generator(self::reply('All good, boss.'))->generate('SYSTEM RULES', 'USER DATA', ['type' => 'object'], 8);

        self::assertSame('All good, boss.', $text);
        self::assertSame('SYSTEM RULES', $this->sentBody['system']);
        self::assertSame([['role' => 'user', 'content' => 'USER DATA']], $this->sentBody['messages']);
        self::assertSame('low', $this->sentBody['output_config']['effort']);
        self::assertSame('claude-haiku-5-5', $this->sentBody['model']);
    }

    public function testAnHttpErrorIsAProviderError(): void
    {
        try {
            $this->generator(new MockResponse('{"error":{}}', ['http_code' => 529]))->generate('s', 'u', [], 8);
            self::fail('expected unavailable');
        } catch (PaAdviceUnavailableException $e) {
            self::assertSame(PaAdviceUnavailableException::PROVIDER_ERROR, $e->reason);
        }
    }

    public function testATimeoutIsATimeout(): void
    {
        try {
            $this->generator(fn () => throw new TimeoutException('Idle timeout reached'))->generate('s', 'u', [], 8);
            self::fail('expected unavailable');
        } catch (PaAdviceUnavailableException $e) {
            self::assertSame(PaAdviceUnavailableException::TIMEOUT, $e->reason);
        }
    }

    public function testWithNoKeyItIsNotConfigured(): void
    {
        self::assertFalse($this->generator(self::reply('x'), key: '')->isConfigured());
    }
}
