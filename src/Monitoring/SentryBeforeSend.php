<?php

declare(strict_types=1);

namespace App\Monitoring;

use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Last filter before an event leaves for Sentry (wired as `before_send` in config/packages/sentry.yaml).
 *
 * Symfony's kernel ErrorListener logs every uncaught HttpException at ERROR, including plain client
 * mistakes: a 404 for a stale route, a 401 from an expired token, a 422 validation failure. Those reach
 * the Monolog → Sentry handler at the same level as a real crash, so on production they would bury the
 * errors that matter. Anything carrying a 4xx status is the client's problem, not ours: drop it.
 * A 5xx HttpException, and every non-HTTP throwable, still goes through.
 */
final class SentryBeforeSend
{
    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $exception = $hint?->exception;

        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return null;
        }

        return $event;
    }
}
