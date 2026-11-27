<?php

declare(strict_types=1);

namespace App\Service\PersonalAssistant;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Server-side cache of worded PA advice (Courtney, 2026-11-27).
 *
 * The advice endpoint is a paid proxy to the AI, so an identical request from ANY owner reuses
 * the last good reply instead of paying again. The key is a hash of everything that shapes the
 * reply:
 *   - the full system prompt as built for this PA (base rules + the PA's stored personality
 *     prompt + name), so an admin edit to a personality, or a rule change in code, is a new key;
 *   - the model, so switching PA_ADVICE_MODEL never serves the old model's words;
 *   - the sanitised request: PA slug, topic, locale, context and findings, with object keys
 *     sorted so the device's key order never splits the cache. `context.gameDate` is left out,
 *     matching the app's own cache: the same figures a week later are the same advice.
 *
 * Only `ok` replies are stored. A cache failure is never an error: it is a miss.
 */
final class PaAdviceCache
{
    /** How long a worded reply is reused. Figures change week to week, so this rarely binds. */
    public const TTL_SECONDS = 30 * 24 * 3600;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly string $model,
        private readonly LoggerInterface $logger,
    ) {}

    public function key(string $systemPrompt, PaAdviceRequest $request): string
    {
        $context = $request->context;
        unset($context['gameDate']);

        $payload = self::sortKeys([
            'model'    => $this->model,
            'system'   => $systemPrompt,
            'pa'       => $request->personalityId,
            'topic'    => $request->topic,
            'locale'   => $request->locale,
            'context'  => $context,
            'findings' => $request->findings,
        ]);

        return 'pa_advice_reply.' . hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    public function get(string $key): ?string
    {
        try {
            $item = $this->cache->getItem($key);
            $value = $item->isHit() ? $item->get() : null;

            return \is_string($value) && $value !== '' ? $value : null;
        } catch (\Throwable $e) {
            $this->logger->warning('Personal Assistant advice cache read failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function set(string $key, string $message): void
    {
        try {
            $item = $this->cache->getItem($key);
            $item->set($message);
            $item->expiresAfter(self::TTL_SECONDS);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->warning('Personal Assistant advice cache write failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Sort associative arrays by key at every depth; lists keep their order (findings are
     * already ordered by the parser: severity, then the device's order).
     */
    private static function sortKeys(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        $out = array_map(self::sortKeys(...), $value);
        if (!array_is_list($out)) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }
}
