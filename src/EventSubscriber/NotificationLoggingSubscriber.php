<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\NotificationLog;
use App\Enum\NotificationLogStatus;
use App\Message\ResolveAdminMessageAudienceForPushMessage;
use App\Message\SendPushNotificationMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Persists one `NotificationLog` row per push-related Messenger message actually processed —
 * the single, uniform place both success and failure are recorded, regardless of *why* a
 * failure happened. Deliberately NOT done inside the handlers themselves: a handler-construction
 * failure (e.g. `Kreait\Firebase\Contract\Messaging` throwing because
 * `FIREBASE_SERVICE_ACCOUNT_JSON` is missing/malformed — the exact bug this subscriber exists to
 * make visible) happens before the handler's own code ever runs, inside
 * `Symfony\Component\Messenger\Middleware\HandleMessageMiddleware`'s per-handler try/catch — so
 * it surfaces as a normal `WorkerMessageFailedEvent`, the same event any other handler exception
 * would raise. Listening here catches every failure mode uniformly instead of requiring each
 * handler to defend against its own construction.
 *
 * Ignores every other message type Messenger might ever carry (e.g. Mailer's
 * `SendEmailMessage`) — only the two push-related messages are logged here.
 */
final class NotificationLoggingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageHandledEvent::class => 'onMessageHandled',
            WorkerMessageFailedEvent::class  => 'onMessageFailed',
        ];
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$this->isLoggable($message)) {
            return;
        }

        $this->persist(new NotificationLog(
            NotificationLogStatus::SUCCESS,
            $this->shortClassName($message),
            $this->summaryFor($message),
            $this->detailFor($message),
        ));
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$this->isLoggable($message)) {
            return;
        }

        $throwable = $event->getThrowable();
        $detail    = $this->detailFor($message);
        $detail['exception'] = [
            'class'   => $throwable::class,
            'message' => $throwable->getMessage(),
            'file'    => $throwable->getFile(),
            'line'    => $throwable->getLine(),
        ];

        $this->persist(new NotificationLog(
            NotificationLogStatus::FAILED,
            $this->shortClassName($message),
            $this->summaryFor($message),
            $detail,
            $throwable->getMessage(),
        ));
    }

    private function isLoggable(object $message): bool
    {
        return $message instanceof SendPushNotificationMessage
            || $message instanceof ResolveAdminMessageAudienceForPushMessage;
    }

    private function shortClassName(object $message): string
    {
        $parts = explode('\\', $message::class);

        return end($parts);
    }

    /** @return array<string, mixed> */
    private function detailFor(object $message): array
    {
        if ($message instanceof SendPushNotificationMessage) {
            return [
                'userIds' => $message->userIds,
                'title'   => $message->title,
                'body'    => $message->body,
                'data'    => $message->data,
            ];
        }

        if ($message instanceof ResolveAdminMessageAudienceForPushMessage) {
            return ['adminMessageId' => $message->adminMessageId];
        }

        return [];
    }

    private function summaryFor(object $message): string
    {
        if ($message instanceof SendPushNotificationMessage) {
            $count = count($message->userIds);

            return sprintf('Push to %d user(s): %s', $count, $message->title);
        }

        if ($message instanceof ResolveAdminMessageAudienceForPushMessage) {
            return sprintf('Resolve push audience for AdminMessage %s', $message->adminMessageId);
        }

        return $this->shortClassName($message);
    }

    private function persist(NotificationLog $log): void
    {
        $this->em->persist($log);
        $this->em->flush();
    }
}
