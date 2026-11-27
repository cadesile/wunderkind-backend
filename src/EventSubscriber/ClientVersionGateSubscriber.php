<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns away app builds that are too old for this server, before they touch anything.
 *
 * Every app build sends `X-Client-Api-Version: <int>`. A build from before that header existed
 * sends nothing and counts as LEGACY_VERSION (1). When the version is below
 * `game_config.min_client_api_version`, the request is answered 426 `update_required` with no
 * controller, no firewall and no database write behind it. 0 turns the gate off.
 *
 * WHY IT EXISTS: at go-live every master-era club is bought out and deleted. A master build
 * that has not yet picked up the OTA would otherwise keep syncing a club that no longer exists.
 * Raising the minimum to 2 makes those builds fail cleanly instead, and new builds show an
 * "update required" screen on the same answer. The same switch serves every later breaking
 * release: bump CLIENT_API_VERSION in the app, ship it, then raise this.
 *
 * Runs ahead of the firewall (priority 16 > 8), so it also covers login and token refresh: an
 * old build cannot sign in and then sync either. Only `/api/` paths; the admin is untouched.
 *
 * The minimum is read with one indexed scalar query and memoised per PHP worker for
 * CACHE_SECONDS, so raising it in the admin takes effect within that time without costing a
 * query on every request.
 */
final class ClientVersionGateSubscriber implements EventSubscriberInterface
{
    public const HEADER         = 'X-Client-Api-Version';
    public const LEGACY_VERSION = 1;
    private const CACHE_SECONDS = 30;

    private ?int $cachedMinimum = null;
    private int $cachedAt = 0;

    public function __construct(private readonly Connection $connection) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 16]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/') || $request->isMethod('OPTIONS')) {
            return;
        }

        $minimum = $this->minimum();
        if ($minimum <= 0) {
            return;
        }

        $sent    = $request->headers->get(self::HEADER);
        $version = ($sent !== null && ctype_digit(trim($sent))) ? (int) trim($sent) : self::LEGACY_VERSION;

        if ($version >= $minimum) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'error'               => 'update_required',
            'message'             => 'This version of the app is no longer supported. Please update to keep playing.',
            'clientApiVersion'    => $version,
            'minClientApiVersion' => $minimum,
        ], 426));
    }

    private function minimum(): int
    {
        $now = time();
        if ($this->cachedMinimum !== null && $now - $this->cachedAt < self::CACHE_SECONDS) {
            return $this->cachedMinimum;
        }

        try {
            $value = $this->connection->fetchOne('SELECT min_client_api_version FROM game_config ORDER BY id LIMIT 1');
        } catch (\Throwable) {
            // Before the migration has run, or with the database down: fail open. The gate is a
            // courtesy to old clients, never a reason for the whole API to stop answering.
            $value = 0;
        }

        $this->cachedMinimum = is_numeric($value) ? (int) $value : 0;
        $this->cachedAt      = $now;

        return $this->cachedMinimum;
    }
}
