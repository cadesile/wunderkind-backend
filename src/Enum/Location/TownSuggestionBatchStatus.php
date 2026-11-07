<?php

declare(strict_types=1);

namespace App\Enum\Location;

/**
 * One batch of a queued "Pull towns" run.
 *
 * Deliberately shorter than WorldPackGenerationTierRunStatus: a batch is one API call plus one
 * import, with no assembly step to be in the middle of.
 *
 * COMPLETED means the call returned and the import ran — not that it added anything. A batch
 * that offers fifty towns we already hold is a completed batch with `imported = 0`, which is
 * the honest outcome for a country whose catalogue the model has run out of ideas for, and is
 * not a failure.
 */
enum TownSuggestionBatchStatus: string
{
    case PENDING   = 'pending';
    case RUNNING   = 'running';
    case COMPLETED = 'completed';
    case FAILED    = 'failed';

    /** Nothing further will happen to a batch in one of these states. */
    public function isTerminal(): bool
    {
        return $this === self::COMPLETED || $this === self::FAILED;
    }
}
