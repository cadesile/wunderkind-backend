<?php

declare(strict_types=1);

namespace App\Enum\Competition;

/**
 * Whether an entrant's bulky submission rows have landed yet.
 *
 * Deliberately a **separate axis from `CompetitionEntrantStatus`**, not a new case on it.
 * A reserved-but-unfilled entrant really is REGISTERED — it holds a capacity slot and has
 * paid its entry fee — and `status` is serialized straight to the client by
 * `CompetitionResponseBuilder::serializeEntrantPreview()`. Introducing a `pending` case
 * there would ship an unrecognised status value to a client already live in production,
 * which is a contract break for a fact the client has no use for. Keeping it on its own
 * column means the queue is invisible to the API.
 *
 * It is also why capacity accounting needed no change: `countForCompetition()` counts
 * everything except WITHDRAWN, so a PENDING entrant holds its slot for free.
 */
enum EntrantSquadState: string
{
    /**
     * Slot reserved, squad/submission not yet written — the message is queued or in flight.
     *
     * A round must not be drawn while any entrant is in this state: the squad row is the
     * match engine's only input, so a fixture drawn against a PENDING entrant would resolve
     * against an empty XI rather than fail loudly. See CompetitionDrawService.
     */
    case PENDING = 'pending';

    /** Squad and submission rows written. The only state a competition may be drawn in. */
    case STORED = 'stored';

    /**
     * The handler exhausted its retries. The envelope is on the `failed` transport for
     * inspection and replay; the entrant still holds its slot, so an operator decides
     * between replaying the message and withdrawing the club. Surfaced by
     * `app:competition:health`, which is the only thing that reports it.
     */
    case FAILED = 'failed';
}
