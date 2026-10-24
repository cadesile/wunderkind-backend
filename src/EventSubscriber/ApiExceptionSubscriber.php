<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Turns any uncaught exception on an `/api/*` route into JSON.
 *
 * Without this, a `#[MapRequestPayload]` validation failure renders Symfony's
 * "An Error Occurred: Unprocessable Content" **HTML** page whenever the request
 * carries no `Accept: application/json` — which is what the app sends. The
 * client then gets a 422 with nothing machine-readable in it: it cannot tell
 * the player which field was rejected, and neither can Sentry. That is Sentry
 * `155644075` (POST /api/owner-avatar during new-game onboarding).
 *
 * The body is RFC 9457 `application/problem+json`, with two additions for this
 * API's own conventions:
 *  - `error`, a stable machine slug, because every hand-written error response
 *    in this codebase already answers `{"error": ...}` and clients branch on it.
 *  - `violations[]`, one entry per constraint violation, so a form can mark the
 *    offending field. This is the part that was missing entirely.
 *
 * Deliberate properties:
 *
 *  - **Runs at priority -48**, between Symfony's security `ExceptionListener`
 *    (1) and `ErrorListener::onKernelException` (-128). Setting a response on
 *    an `ExceptionEvent` stops propagation, so an authentication failure the
 *    firewall has already answered — lexik's own JSON 401 — is left alone, and
 *    the HTML renderer never gets a look at anything under `/api`.
 *  - **Only `/api` paths.** `/admin` is a browser UI and must keep Symfony's
 *    HTML error pages.
 *  - **5xx never leaks its message** outside debug. The exception text is
 *    logged instead; the client gets a fixed string. 4xx messages are either
 *    Symfony's own or the validator's, and are safe to return.
 *  - **Controllers that catch their own errors are unaffected** — those return
 *    an ordinary `JsonResponse` and never reach kernel.exception. This only
 *    replaces responses that were previously HTML.
 */
class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public const PRIORITY = -48;

    /** Returned instead of the real message for any 5xx outside debug. */
    private const GENERIC_SERVER_ERROR = 'An internal server error occurred.';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', self::PRIORITY]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if ($path !== '/api' && !str_starts_with($path, '/api/')) {
            return;
        }

        $throwable = $event->getThrowable();
        $status    = $throwable instanceof HttpExceptionInterface
            ? $throwable->getStatusCode()
            : Response::HTTP_INTERNAL_SERVER_ERROR;
        $headers   = $throwable instanceof HttpExceptionInterface ? $throwable->getHeaders() : [];

        $violations = $this->extractViolations($throwable);
        $isServer   = $status >= 500;

        $detail = match (true) {
            $isServer && !$this->debug => self::GENERIC_SERVER_ERROR,
            default                    => $throwable->getMessage(),
        };

        $body = [
            'type'   => 'about:blank',
            'title'  => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'error'  => $this->errorSlug($status, $violations !== []),
            'detail' => $detail,
        ];
        if ($violations !== []) {
            $body['violations'] = $violations;
        }

        $this->log($throwable, $path, $status, $violations, $isServer);

        $response = new JsonResponse($body, $status, $headers);
        $response->headers->set('Content-Type', 'application/problem+json');
        $event->setResponse($response);
    }

    /**
     * Flattens whatever the failing layer attached to the exception into one
     * `violations[]` shape.
     *
     * `#[MapRequestPayload]` wraps both of these as the *previous* exception (see
     * Symfony's RequestPayloadValueResolver). With a validator wired up — which is the
     * case here — a type the DTO cannot hold is folded into the constraint violations
     * too, so `"dob": 1990` against a `?string` arrives as a `ValidationFailedException`
     * reading "This value should be of type null|string." The
     * `PartialDenormalizationException` branch covers the resolver's no-validator path,
     * where those same errors are all the exception carries.
     *
     * @return list<array{propertyPath: string, message: string, code: ?string}>
     */
    private function extractViolations(\Throwable $throwable): array
    {
        for ($e = $throwable; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof ValidationFailedException) {
                $out = [];
                foreach ($e->getViolations() as $violation) {
                    $out[] = [
                        'propertyPath' => $violation->getPropertyPath(),
                        'message'      => (string) $violation->getMessage(),
                        'code'         => $violation->getCode(),
                    ];
                }

                return $out;
            }

            if ($e instanceof PartialDenormalizationException) {
                $out = [];
                foreach ($e->getErrors() as $error) {
                    $expected = $error->getExpectedTypes() ?? [];
                    $out[] = [
                        'propertyPath' => (string) $error->getPath(),
                        'message'      => $expected === []
                            ? $error->getMessage()
                            : sprintf('This value should be of type %s.', implode('|', $expected)),
                        'code'         => 'invalid_type',
                    ];
                }

                return $out;
            }
        }

        return [];
    }

    private function errorSlug(int $status, bool $hasViolations): string
    {
        if ($hasViolations) {
            return 'validation_failed';
        }

        return match ($status) {
            Response::HTTP_BAD_REQUEST           => 'bad_request',
            Response::HTTP_UNAUTHORIZED          => 'unauthorized',
            Response::HTTP_FORBIDDEN             => 'forbidden',
            Response::HTTP_NOT_FOUND             => 'not_found',
            Response::HTTP_METHOD_NOT_ALLOWED    => 'method_not_allowed',
            Response::HTTP_CONFLICT              => 'conflict',
            Response::HTTP_PRECONDITION_FAILED   => 'precondition_failed',
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE => 'unsupported_media_type',
            Response::HTTP_UNPROCESSABLE_ENTITY  => 'unprocessable_entity',
            Response::HTTP_TOO_MANY_REQUESTS     => 'too_many_requests',
            default                              => $status >= 500 ? 'server_error' : 'error',
        };
    }

    /**
     * @param list<array{propertyPath: string, message: string, code: ?string}> $violations
     */
    private function log(\Throwable $throwable, string $path, int $status, array $violations, bool $isServer): void
    {
        $context = [
            'path'       => $path,
            'status'     => $status,
            'exception'  => $throwable::class,
            'message'    => $throwable->getMessage(),
            'violations' => $violations,
        ];

        // 5xx is already logged with a stack trace by Symfony's own ErrorListener
        // at priority 0; this adds the API-shaped context, not a second trace.
        if ($isServer) {
            $this->logger->error('api.exception', $context);

            return;
        }

        $this->logger->warning('api.client_error', $context);
    }
}
