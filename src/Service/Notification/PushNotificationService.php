<?php

namespace App\Service\Notification;

use App\Message\SendPushNotificationMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The only thing a call site should touch to send a push notification — dispatches
 * asynchronously via Messenger (SendPushNotificationMessageHandler does the actual FCM call).
 * No call site should talk to Messenger or Firebase directly.
 */
class PushNotificationService
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {}

    /**
     * @param list<string> $userIds
     * @param array<string, scalar> $data FCM data payload — a `type` discriminator plus
     *        relevant ids is the convention (see call sites), so the client can deep-link on
     *        tap. Values are cast to string here since FCM requires every data value to be one.
     */
    public function notifyUsers(array $userIds, string $title, string $body, array $data = []): void
    {
        if ($userIds === []) {
            return;
        }

        $this->messageBus->dispatch(new SendPushNotificationMessage(
            array_values($userIds),
            $title,
            $body,
            array_map(strval(...), $data),
        ));
    }
}
