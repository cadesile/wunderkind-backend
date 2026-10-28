<?php

declare(strict_types=1);

namespace App\Message\Competition;

/**
 * Publish results for one CompetitionRound.
 *
 * The resolve counterpart of DrawCompetitionRoundMessage, and identical in shape and
 * reasoning: id only, claim taken in the handler.
 *
 * This is the message that actually needed queueing. A round resolution runs the match
 * engine and narrative generation for every fixture in the round — up to 32 of them — and
 * the cron tick used to do that for *every* due round inline, in one process. With several
 * tournaments rolling concurrently that is unbounded work in a single tick; here the tick
 * writes one row per due round and the consumer decides how fast to drain it.
 */
final class ResolveCompetitionRoundMessage
{
    public function __construct(
        public readonly string $roundId,
    ) {}
}
