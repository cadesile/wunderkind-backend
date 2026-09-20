<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched once, right when `app:competition:provision-instances` actually creates a new
 * REGISTERING instance (not on every tick that finds an existing one still open) — deferred to a
 * handler for the same reason as `ResolveAdminMessageAudienceForPushMessage`: evaluating
 * eligibility across every club with a registered device shouldn't block the cron's own tick.
 */
final class ResolveNewCompetitionAudienceForPushMessage implements AudienceResolutionMessage
{
    public function __construct(
        public readonly string $activeCompetitionId,
    ) {}

    public function auditSubject(): string
    {
        return sprintf('ActiveCompetition %s', $this->activeCompetitionId);
    }
}
