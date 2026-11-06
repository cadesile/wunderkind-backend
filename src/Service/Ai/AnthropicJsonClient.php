<?php

declare(strict_types=1);

namespace App\Service\Ai;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One POST to the Claude Messages API, with a JSON schema the answer is held to.
 *
 * Extracted from ClaudeLocationEnricher once a second feature needed to call the same API.
 * Auth, the pinned API version, the structured-output request shape and the difference
 * between "could not ask" and "asked, got nonsense" are the kind of thing that must have
 * exactly one implementation: two copies drift, and the copy that drifts is the one nobody
 * is looking at when a key rotates.
 *
 * RAW HTTP OVER THE SHARED HttpClientInterface, no vendor SDK — the original reasoning holds:
 * YouTubeFeedService and SocialPostingService already talk to external APIs this way, and a
 * Composer dependency for one endpoint is not worth the supply-chain surface.
 *
 * STRUCTURED OUTPUTS are the point. `output_config.format` with a schema makes the reply
 * schema-valid by construction rather than parsed hopefully, which matters most for the
 * failure that costs the most: a reply that reads perfectly but silently omits a field.
 *
 * IT DOES NOT VALIDATE AND DOES NOT WRITE. Schema-valid is not the same as true — a
 * coordinate can be in the Atlantic and a town can be invented. Judging the content is the
 * caller's job.
 */
final class AnthropicJsonClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** The long-stable Messages API version. Pinned, not latest, so a server-side change cannot alter parsing. */
    private const API_VERSION = '2023-06-01';

    private const DEFAULT_TIMEOUT_SECONDS = 45;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $model,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->model !== '';
    }

    /**
     * Ask for an object matching $schema, and return it decoded.
     *
     * $context names what was being asked about and appears in any error — "Córdoba", or
     * "town list for Argentina". Without it a failure in a batch is untraceable.
     *
     * @param array<string, mixed> $schema
     *
     * @throws AiUnavailableException
     */
    public function sendJson(string $prompt, array $schema, int $maxTokens, string $context, ?int $timeoutSeconds = null): AnthropicJsonResponse
    {
        $payload = $this->send($prompt, $maxTokens, $schema, $timeoutSeconds);

        // The structured-outputs contract puts the schema-valid JSON in the first text block as
        // a string. Parsed defensively anyway: a transport-level success with an unexpected
        // body shape must be an error we can see, not a silently empty result that looks like a
        // provider answering unhelpfully.
        $text = $payload['content'][0]['text'] ?? null;

        if (!\is_string($text)) {
            throw new AiUnavailableException(\sprintf(
                'Claude returned no text block for %s (stop_reason: %s).',
                $context,
                (string) ($payload['stop_reason'] ?? 'unknown'),
            ));
        }

        $decoded = json_decode($text, true);

        if (!\is_array($decoded)) {
            throw new AiUnavailableException(\sprintf('Claude returned text that is not JSON for %s.', $context));
        }

        return new AnthropicJsonResponse(
            data: $decoded,
            inputTokens: self::intOrNull($payload['usage']['input_tokens'] ?? null),
            outputTokens: self::intOrNull($payload['usage']['output_tokens'] ?? null),
        );
    }

    /**
     * One Messages API call, returning the raw envelope.
     *
     * Public because a connection check wants a schema-free single-token round trip, and
     * forcing a schema onto that would make the cheapest possible call more expensive and
     * more likely to fail for reasons unrelated to connectivity.
     *
     * @param array<string, mixed>|null $schema null sends no output_config at all
     *
     * @return array<string, mixed>
     *
     * @throws AiUnavailableException on any transport, auth, rate-limit or server error
     */
    public function send(string $prompt, int $maxTokens, ?array $schema = null, ?int $timeoutSeconds = null): array
    {
        $body = [
            'model'      => $this->model,
            'max_tokens' => $maxTokens,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ];

        if ($schema !== null) {
            $body['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $schema]];
        }

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => [
                    'content-type'      => 'application/json',
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                ],
                'json'    => $body,
                'timeout' => $timeoutSeconds ?? self::DEFAULT_TIMEOUT_SECONDS,
            ]);

            $status = $response->getStatusCode();
            $raw    = $response->getContent(throw: false);
        } catch (HttpExceptionInterface $e) {
            // A transport failure is "could not ask", which callers record as an error —
            // deliberately distinct from "asked, got nonsense".
            throw new AiUnavailableException(
                \sprintf('Could not reach the Claude API: %s', $e->getMessage()),
                previous: $e,
            );
        }

        if ($status !== 200) {
            $this->logger->error('Claude API call failed', [
                'status' => $status,
                // Truncated: an error body can be large and this lands in a varchar(255) column
                // downstream. The status code is the part that identifies the problem.
                'body'   => substr($raw, 0, 500),
            ]);

            throw new AiUnavailableException(
                \sprintf('Claude API returned HTTP %d: %s', $status, substr($raw, 0, 200)),
            );
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            throw new AiUnavailableException('Claude API returned a body that is not JSON.');
        }

        return $decoded;
    }

    private static function intOrNull(mixed $v): ?int
    {
        return \is_int($v) || (\is_float($v) && $v == (int) $v) ? (int) $v : null;
    }
}
