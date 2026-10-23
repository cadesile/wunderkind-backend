<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\IdempotencyRecord;
use App\Entity\User;
use App\Repository\IdempotencyRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\UuidV7;

/**
 * Supports the `Idempotency-Key` header on POST /api/competitions/*, POST /api/friendlies/*,
 * and POST /api/inbox/* endpoints.
 *
 * Keyed by (user_id, method, path, idempotency_key) with a 24h TTL:
 * - Same key + same request body hash -> replays the cached status & JSON body.
 * - Same key + different request body hash -> 422 idempotency_key_reused.
 */
class IdempotencySubscriber implements EventSubscriberInterface
{
    public const HEADER_NAME = 'Idempotency-Key';
    private const ATTR_PENDING_KEY = '_idempotency_pending';

    public function __construct(
        private readonly Security $security,
        private readonly IdempotencyRecordRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onKernelRequest', 5],
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->getMethod() !== 'POST') {
            return;
        }

        $key = $request->headers->get(self::HEADER_NAME);
        if ($key === null || trim($key) === '') {
            return;
        }
        $key = substr(trim($key), 0, 120);

        $path = $request->getPathInfo();
        if (
            !str_starts_with($path, '/api/competitions/')
            && !str_starts_with($path, '/api/friendlies/')
            && !str_starts_with($path, '/api/inbox/')
        ) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $userId   = (string) $user->getId();
        $method   = $request->getMethod();
        $bodyHash = hash('sha256', (string) $request->getContent());

        try {
            $existing = $this->repository->findActiveRecord($userId, $method, $path, $key);
        } catch (\Throwable) {
            return;
        }

        if ($existing !== null) {
            if ($existing->getRequestBodyHash() !== $bodyHash) {
                $event->setResponse(new JsonResponse([
                    'error'   => 'idempotency_key_reused',
                    'message' => 'This Idempotency-Key was already used with a different request payload.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY));

                return;
            }

            $event->setResponse(new JsonResponse(
                $existing->getResponseBody(),
                $existing->getResponseStatus(),
                ['X-Idempotent-Replay' => 'true'],
                json: true,
            ));

            return;
        }

        $request->attributes->set(self::ATTR_PENDING_KEY, [
            'userId'   => $userId,
            'method'   => $method,
            'path'     => $path,
            'key'      => $key,
            'bodyHash' => $bodyHash,
        ]);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $pending = $request->attributes->get(self::ATTR_PENDING_KEY);
        if (!is_array($pending)) {
            return;
        }

        $response = $event->getResponse();
        $status   = $response->getStatusCode();
        // Cache 2xx and deterministic 4xx business responses, never 5xx server errors
        if ($status >= 500) {
            return;
        }

        try {
            $id  = new UuidV7();
            $now = new \DateTimeImmutable();
            $this->em->getConnection()->executeStatement(
                <<<'SQL'
                INSERT INTO idempotency_record
                    (id, user_id, method, path, idempotency_key, request_body_hash, response_status, response_body, created_at)
                VALUES (:id, :userId, :method, :path, :key, :bodyHash, :status, :body, :createdAt)
                ON CONFLICT (user_id, method, path, idempotency_key) DO UPDATE
                    SET request_body_hash = EXCLUDED.request_body_hash,
                        response_status = EXCLUDED.response_status,
                        response_body = EXCLUDED.response_body,
                        created_at = EXCLUDED.created_at
                SQL,
                [
                    'id'        => $id->toRfc4122(),
                    'userId'    => $pending['userId'],
                    'method'    => $pending['method'],
                    'path'      => $pending['path'],
                    'key'       => $pending['key'],
                    'bodyHash'  => $pending['bodyHash'],
                    'status'    => $status,
                    'body'      => (string) $response->getContent(),
                    'createdAt' => $now->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\Throwable) {
            // Never fail a completed response if idempotency persistence fails
        }
    }
}
