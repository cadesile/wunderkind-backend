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

    /**
     * The value clients see, which is deliberately not this enum's own.
     *
     * `stored` describes a database row and is the right word internally; a client is being
     * told whether its entry is *confirmed*, which is the question the player is actually
     * asking. Mapping at this one boundary keeps the server vocabulary honest without
     * leaking storage mechanics into an API, and means renaming a case here cannot silently
     * change a published contract.
     *
     * Contract: `docs/api/competition-registration-status.md`.
     */
    public function clientValue(): string
    {
        return match ($this) {
            self::PENDING => 'pending',
            self::STORED  => 'confirmed',
            self::FAILED  => 'failed',
        };
    }

    /**
     * Whether a client should treat the entry as settled — i.e. stop polling.
     *
     * FAILED counts as settled: it is terminal without an operator, so a client that kept
     * polling it would poll forever. The two are distinguished by `clientValue()`, not here.
     */
    public function isSettled(): bool
    {
        return $this !== self::PENDING;
    }
}
