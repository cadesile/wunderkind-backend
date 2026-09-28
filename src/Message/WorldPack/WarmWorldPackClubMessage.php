<?php

namespace App\Message\WorldPack;

/**
 * Dispatched once per club by WarmWorldPackTierMessageHandler, handled by
 * WarmWorldPackClubMessageHandler — generates that club's players/staff/scouts fresh
 * (WorldPackClubGenerationService) and stores the built snapshot on the club-run row.
 *
 * Also self-redispatched by the handler (same message, same clubRunId) on a caught
 * generation failure, up to a bounded attempt count — see the handler for why this is
 * self-managed rather than left to Messenger's own retry_strategy.
 */
final class WarmWorldPackClubMessage
{
    public function __construct(
        public readonly string $clubRunId,
    ) {}
}
