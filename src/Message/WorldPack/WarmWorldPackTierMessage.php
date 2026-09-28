<?php

namespace App\Message\WorldPack;

/**
 * Dispatched once per tier by WorldPackGenerationOrchestrator::startRun(), handled by
 * WarmWorldPackTierMessageHandler — claims the tier row, creates one
 * WorldPackGenerationClubRun per club in the tier, and dispatches one
 * WarmWorldPackClubMessage per club.
 */
final class WarmWorldPackTierMessage
{
    public function __construct(
        public readonly string $tierRunId,
    ) {}
}
