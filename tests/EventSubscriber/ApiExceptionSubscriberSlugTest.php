<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ApiExceptionSubscriber;
use App\Exception\ClubMismatchException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A club mismatch and an account-level refusal both answer 403, and the app needs to tell
 * them apart: it signs out on an account-level 403, and must not on a club mismatch, since
 * logging back in cannot change that answer. The `error` slug is how it tells.
 */
class ApiExceptionSubscriberSlugTest extends TestCase
{
    private function errorFor(\Throwable $throwable): array
    {
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/api/sync', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );

        (new ApiExceptionSubscriber(new NullLogger()))->onKernelException($event);

        return json_decode((string) $event->getResponse()?->getContent(), true);
    }

    public function testAClubMismatchHasItsOwnSlug(): void
    {
        $body = $this->errorFor(new ClubMismatchException('0199abc'));

        self::assertSame(403, $body['status']);
        self::assertSame('club_mismatch', $body['error']);
    }

    public function testAnyOther403IsStillForbidden(): void
    {
        $body = $this->errorFor(new AccessDeniedHttpException('Access Denied.'));

        self::assertSame(403, $body['status']);
        self::assertSame('forbidden', $body['error']);
    }
}
