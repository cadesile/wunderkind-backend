<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ApiExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * Dev reports WARNING and above to Sentry, so the level `api.client_error` is logged at decides
 * whether a 4xx becomes an issue. Scanner probes for paths no route matches (WUNDERKIND-BACKEND-8)
 * must stay below it; a 404 a matched controller chose to answer must not.
 */
class ApiExceptionSubscriberLogLevelTest extends TestCase
{
    /** @return list<array{string, string}> level and message of each log call */
    private function logsFor(\Throwable $throwable): array
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message];
            }
        };

        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/api/v1/targets', 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );

        (new ApiExceptionSubscriber($logger))->onKernelException($event);

        return $logger->records;
    }

    public function testAnUnroutedPathIsLoggedAtInfo(): void
    {
        $noRoute = new NotFoundHttpException(
            'No route found for "GET /api/v1/targets"',
            new ResourceNotFoundException('No routes found for "/api/v1/targets/".'),
        );

        self::assertSame([['info', 'api.client_error']], $this->logsFor($noRoute));
    }

    public function testAControllerAnswered404StaysAWarning(): void
    {
        self::assertSame(
            [['warning', 'api.client_error']],
            $this->logsFor(new NotFoundHttpException('Club not found.')),
        );
    }

    public function testARouterMethodNotAllowedIsLoggedAtInfo(): void
    {
        // What RouterListener throws for `POST /api` (the scanner in WUNDERKIND-BACKEND-8).
        $wrongMethod = new MethodNotAllowedHttpException(
            ['GET'],
            'No route found for "POST https://dev.buildmyclub.co.uk/api": Method Not Allowed (Allow: GET)',
            new MethodNotAllowedException(['GET']),
        );

        self::assertSame([['info', 'api.client_error']], $this->logsFor($wrongMethod));
    }

    public function testAControllerAnswered405StaysAWarning(): void
    {
        self::assertSame(
            [['warning', 'api.client_error']],
            $this->logsFor(new MethodNotAllowedHttpException(['GET'], 'Not allowed here.')),
        );
    }
}
