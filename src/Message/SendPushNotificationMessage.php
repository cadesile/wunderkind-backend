<?php

namespace App\Message;

/**
 * Dispatched via PushNotificationService, handled by SendPushNotificationMessageHandler.
 * Carries user ids (not resolved device tokens) — devices are looked up fresh at handle time,
 * so a token registered or removed between dispatch and consume is still handled correctly.
 */
final class SendPushNotificationMessage
{
    /**
     * @param list<string> $userIds
     * @param array<string, string> $data FCM data payload — every value must already be a string.
     */
    public function __construct(
        public readonly array $userIds,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {}
}
