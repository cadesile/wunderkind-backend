<?php

declare(strict_types=1);

namespace App\Service\PersonalAssistant;

use App\Service\Ai\AiUnavailableException;
use App\Service\Ai\AnthropicJsonClient;
use Symfony\Component\HttpClient\Exception\TimeoutException;

/**
 * Words PA advice through the shared AnthropicJsonClient.
 *
 * The client is a separate service instance (`app.ai.pa_advice_client`): the SAME API key as
 * every other Claude caller, but its own model, PA_ADVICE_MODEL, because advice is a short,
 * high-volume rewording job that wants a fast, cheap model rather than the enricher's.
 *
 * LOW EFFORT, SMALL BUDGET. This is rewording, not reasoning, and thinking tokens come out of
 * the same max_tokens as the answer, so effort is pinned low and the budget sized for a
 * 400-character reply plus headroom.
 */
final class ClaudePaAdviceGenerator implements PaAdviceGeneratorInterface
{
    private const MAX_TOKENS = 1024;

    public function __construct(private readonly AnthropicJsonClient $client) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function generate(string $system, string $user, array $schema, int $timeoutSeconds): string
    {
        try {
            $response = $this->client->sendJson(
                prompt: $user,
                schema: $schema,
                maxTokens: self::MAX_TOKENS,
                context: 'personal assistant advice',
                timeoutSeconds: $timeoutSeconds,
                effort: AnthropicJsonClient::EFFORT_LOW,
                system: $system,
            );
        } catch (AiUnavailableException $e) {
            throw new PaAdviceUnavailableException(
                self::isTimeout($e) ? PaAdviceUnavailableException::TIMEOUT : PaAdviceUnavailableException::PROVIDER_ERROR,
                $e->getMessage(),
                $e,
            );
        }

        $message = $response->data['message'] ?? null;
        if (!\is_string($message)) {
            throw new PaAdviceUnavailableException(PaAdviceUnavailableException::PROVIDER_ERROR, 'The reply had no message string.');
        }

        return $message;
    }

    private static function isTimeout(\Throwable $e): bool
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if ($t instanceof TimeoutException || stripos($t->getMessage(), 'timeout') !== false || stripos($t->getMessage(), 'timed out') !== false) {
                return true;
            }
        }

        return false;
    }
}
