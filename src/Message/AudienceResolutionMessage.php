<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Marker for a Messenger message whose handler eagerly resolves a push audience (evaluates
 * eligibility across every club with a registered device, then dispatches individual
 * `SendPushNotificationMessage`s to whoever qualifies) rather than sending a push directly.
 * Implemented by `ResolveAdminMessageAudienceForPushMessage` and
 * `ResolveNewCompetitionAudienceForPushMessage` — lets `NotificationLoggingSubscriber` log both
 * uniformly (including surfacing the handler's resolved recipient count, via `HandledStamp`)
 * without duplicating that logic per message type.
 */
interface AudienceResolutionMessage
{
    /** Human-readable subject for the Notification Log summary, e.g. "AdminMessage <id>". */
    public function auditSubject(): string;
}
