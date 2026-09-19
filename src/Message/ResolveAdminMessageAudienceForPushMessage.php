<?php

namespace App\Message;

/**
 * Dispatched once, at AdminMessage creation/activation (AdminMessageCrudController), when an
 * admin has checked "send as push" — never on the poll path. Deferred to a handler because
 * resolving a broadcast/group-segmented audience can be slow (evaluates every club with a
 * registered device), which shouldn't block the admin's save request.
 */
final class ResolveAdminMessageAudienceForPushMessage
{
    public function __construct(
        public readonly string $adminMessageId,
    ) {}
}
