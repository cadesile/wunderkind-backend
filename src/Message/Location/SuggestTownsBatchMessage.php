<?php

declare(strict_types=1);

namespace App\Message\Location;

/**
 * Ask the AI provider for one batch of town names for one country.
 *
 * WHY BATCHES AT ALL. The synchronous version this replaces made a single call for up to 300
 * towns — a ~12,000 output-token generation taking 60-180s, which nginx's 60s default cut off
 * while PHP kept waiting. Splitting it means each call is ~2,048 tokens and seconds long, one
 * bad batch costs one batch, towns land progressively, and "batch 3 of 6" is real progress
 * rather than a spinner.
 *
 * Carries ids and scalars only, like EnrichLocationMessage — the handler re-reads the batch
 * row, so a duplicate delivery is cheap and a message whose run has been deleted is a no-op.
 *
 * NO EXCLUSION LIST ON THE MESSAGE, deliberately, though the handler needs one. Every batch of
 * a run is dispatched up front — that is what makes the total knowable and the progress bar
 * honest — so at dispatch time such a list could only be empty. And a payload is immutable,
 * so a retried batch would replay a stale one. The handler reads the names its sibling batches
 * have offered out of the database instead; see TownSuggestionBatchRepository.
 */
final class SuggestTownsBatchMessage
{
    public function __construct(
        /** The TownSuggestionBatch row this message does the work for. */
        public readonly string $batchId,
    ) {}
}
