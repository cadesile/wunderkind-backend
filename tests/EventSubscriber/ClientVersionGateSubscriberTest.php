<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ClientVersionGateSubscriber;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The go-live gate: builds below game_config.min_client_api_version get 426 update_required,
 * a build that sends no header counts as version 1, and 0 turns the gate off.
 */
class ClientVersionGateSubscriberTest extends TestCase
{
    private function gate(int $minimum, string $path, ?string $header, string $method = 'GET'): ?array
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn((string) $minimum);

        $request = Request::create($path, $method);
        if ($header !== null) {
            $request->headers->set(ClientVersionGateSubscriber::HEADER, $header);
        }

        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new ClientVersionGateSubscriber($connection))->onKernelRequest($event);

        $response = $event->getResponse();
        if ($response === null) {
            return null;
        }

        return ['status' => $response->getStatusCode()] + json_decode((string) $response->getContent(), true);
    }

    public function testGateOffLetsEverythingThrough(): void
    {
        self::assertNull($this->gate(0, '/api/sync', null));
    }

    public function testABuildWithNoHeaderIsVersionOneAndIsTurnedAway(): void
    {
        $body = $this->gate(2, '/api/sync', null, 'POST');

        self::assertSame(426, $body['status']);
        self::assertSame('update_required', $body['error']);
        self::assertSame(1, $body['clientApiVersion']);
        self::assertSame(2, $body['minClientApiVersion']);
    }

    public function testLoginIsGatedToo(): void
    {
        self::assertSame(426, $this->gate(2, '/api/auth/login', '1', 'POST')['status']);
    }

    public function testACurrentBuildPasses(): void
    {
        self::assertNull($this->gate(2, '/api/sync', '2'));
        self::assertNull($this->gate(2, '/api/sync', '3'));
    }

    public function testAGarbageHeaderCountsAsLegacy(): void
    {
        self::assertSame(426, $this->gate(2, '/api/sync', 'abc')['status']);
    }

    public function testNonApiPathsAndPreflightAreNeverGated(): void
    {
        self::assertNull($this->gate(2, '/admin/game-config', null));
        self::assertNull($this->gate(2, '/api/sync', null, 'OPTIONS'));
    }

    public function testADatabaseErrorFailsOpen(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('no column yet'));

        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create('/api/sync'), HttpKernelInterface::MAIN_REQUEST);
        (new ClientVersionGateSubscriber($connection))->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }
}
