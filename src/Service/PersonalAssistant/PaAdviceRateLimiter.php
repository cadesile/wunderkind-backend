<?php

declare(strict_types=1);

namespace App\Service\PersonalAssistant;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * The per-owner daily cap on paid AI wording calls.
 *
 * THE LIMIT IS READ ON EVERY CALL (Courtney, 2026-11-27: a backend-configured value), which is
 * why this builds its factory per call instead of using a `framework.rate_limiter` block: that
 * config is fixed at container compile time, so an admin edit to game_config would need a
 * deploy. The window state lives in the shared app cache and only stores the hit count, so a
 * changed limit applies to the current window immediately.
 *
 * A fixed one-day window per user. 0 is handled by the caller (AI wording off), never here.
 */
final class PaAdviceRateLimiter
{
    public function __construct(private readonly CacheItemPoolInterface $cache) {}

    /**
     * Spend one call. Returns null when allowed, or the seconds until the window resets.
     */
    public function consume(string $userId, int $dailyLimit): ?int
    {
        $factory = new RateLimiterFactory(
            ['id' => 'pa_advice', 'policy' => 'fixed_window', 'limit' => max(1, $dailyLimit), 'interval' => '1 day'],
            new CacheStorage($this->cache),
        );

        $limit = $factory->create($userId)->consume(1);
        if ($limit->isAccepted()) {
            return null;
        }

        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
