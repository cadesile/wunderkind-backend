<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\NotificationLog;
use App\Enum\NotificationLogStatus;
use App\Message\AudienceResolutionMessage;
use App\Message\SendPushNotificationMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Stamp\HandledStamp;

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

        $detail = $this->detailFor($message);
        $summary = $this->summaryFor($message);

        if ($message instanceof AudienceResolutionMessage) {
            // The handler returns the resolved recipient count precisely so this can tell
            // "resolved 0 recipients, dispatched nothing" apart from "actually dispatched
            // pushes" — both fire an identical WorkerMessageHandledEvent otherwise, which is
            // exactly what made a real "no device was ever registered for this club" case look
            // indistinguishable from a genuine successful send in the admin's Notification Log.
            $resolvedCount = $event->getEnvelope()->last(HandledStamp::class)?->getResult();
            if (is_int($resolvedCount)) {
                $detail['resolvedRecipientCount'] = $resolvedCount;
                $summary = $resolvedCount === 0
                    ? sprintf('%s — 0 eligible recipients found, no push dispatched', $summary)
                    : sprintf('%s — %d recipient(s)', $summary, $resolvedCount);
            }
        }

        $this->persist(new NotificationLog(
            NotificationLogStatus::SUCCESS,
            $this->shortClassName($message),
            $summary,
            $detail,
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
            || $message instanceof AudienceResolutionMessage;
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

        if ($message instanceof AudienceResolutionMessage) {
            return ['subject' => $message->auditSubject()];
        }

        return [];
    }

    private function summaryFor(object $message): string
    {
        if ($message instanceof SendPushNotificationMessage) {
            $count = count($message->userIds);

            return sprintf('Push to %d user(s): %s', $count, $message->title);
        }

        if ($message instanceof AudienceResolutionMessage) {
            return sprintf('Resolve push audience for %s', $message->auditSubject());
        }

        return $this->shortClassName($message);
    }

    private function persist(NotificationLog $log): void
    {
        $this->em->persist($log);
        $this->em->flush();
    }
}
