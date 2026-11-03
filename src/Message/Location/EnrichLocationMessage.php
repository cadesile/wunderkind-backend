<?php

declare(strict_types=1);

namespace App\Message\Location;

/**
 * Fetch the missing geography for one location from an AI provider.
 *
 * Carries ids only, like DrawCompetitionRoundMessage — the handler re-reads the row, so a
 * duplicate delivery is free and a message that outlives its location is a no-op rather than
 * an error.
 *
 * WHERE THIS DIFFERS FROM THE COMPETITION PRECEDENT, AND WHY. DrawCompetitionRoundMessage
 * deliberately does NOT claim at dispatch, because its lease would count down while the
 * message sat in a queue. Enrichment cannot follow that: the entire point of claiming is to
 * stop fifty simultaneous viewers of one un-enriched town producing fifty paid API calls, and
 * that collapse has to happen at dispatch or it does not happen at all.
 *
 * So the two concerns are split across two columns rather than one:
 *   - `enrichment_status = queued` means "a message for this row exists". Set at dispatch, and
 *     the thing that dedupes.
 *   - `enrichment_claimed_at` is the lease, and the HANDLER refreshes it when it actually
 *     starts work — so a long queue backlog no longer expires its own claim, which is exactly
 *     the failure the competition docblock warns about.
 *
 * The residual risk is honest and bounded: if a backlog outlasts the lease, a reader can
 * re-queue a row whose message is still valid, and the location is enriched twice. That costs
 * one duplicate API call, not corruption — the second write is idempotent, and
 * Location::MAX_ENRICHMENT_ATTEMPTS caps how often it can happen at all.
 */
final class EnrichLocationMessage
{
    public function __construct(
        public readonly string $locationId,
        /** The batch this belongs to, for counters. Null for a lazy, reader-triggered call. */
        public readonly ?string $runId = null,
    ) {}
}
