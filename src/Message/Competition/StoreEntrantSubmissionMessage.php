<?php

declare(strict_types=1);

namespace App\Message\Competition;

/**
 * Carries one entrant's submitted envelope to be written outside the registration request.
 *
 * **This is the one message in the codebase that carries a payload rather than just an id**,
 * and the deviation is deliberate. Every other competition message ("each message carries
 * only a round id; the handler re-reads the round") can re-read its subject from a table
 * because the subject is already stored. Here the envelope is precisely what has *not* been
 * stored yet — parking it somewhere first so the message could stay small would mean writing
 * the same ~1.4MB twice, which is the cost queueing was meant to move rather than double.
 *
 * The consequence to know: these rows are large in `messenger_messages`, so the registration
 * transport is drained by its own always-on worker rather than a once-a-minute cron, and a
 * backlog here is a storage concern as well as a latency one.
 *
 * Replay-safe: the handler's writes are upserts keyed on `entrant_id`, so a duplicate
 * delivery or a retry after a partial failure rewrites the same rows rather than duplicating
 * them.
 */
final class StoreEntrantSubmissionMessage
{
    /**
     * @param string               $entrantId RFC4122 uuid of the reserved CompetitionEntrant.
     * @param array<string, mixed> $envelope  The posted body, exactly as SnapshotValidator passed it.
     */
    public function __construct(
        public readonly string $entrantId,
        public readonly array $envelope,
    ) {}
}
