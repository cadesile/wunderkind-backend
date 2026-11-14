<?php

declare(strict_types=1);

namespace App\Enum\Club;

/**
 * Where a club buyout has got to.
 *
 * THREE STATES AND NO "IN PROGRESS", deliberately. The work is one Messenger message whose
 * handler either completes or throws into the `failed` transport; a fourth state claiming the
 * worker is mid-flight would be a lie the moment that worker died, and nothing would ever clear
 * it. PENDING means "queued or being attempted", which is both honest and self-healing — a
 * retry of a PENDING sale is safe, because the ledger credit is idempotent on the sale id.
 *
 * COMPLETED IS TERMINAL AND MEANS THE CLUB IS GONE. There is no reversal here: ClubSaleService
 * deletes the club after crediting, so there is nothing left to restore. A mistaken payout is
 * corrected the way every other money mistake in this codebase is — a REVERSAL entry on the
 * ledger, never by editing or re-running this row. See UserLedgerEntryType::REVERSAL.
 */
enum ClubSaleStatus: string
{
    /** Authorised by an admin, queued for the worker. Safe to retry. */
    case PENDING = 'pending';

    /** Credited and scrubbed. The club no longer exists. */
    case COMPLETED = 'completed';

    /**
     * The handler gave up. Nothing was credited, or the credit landed and the scrub did not —
     * `failureReason` says which, and the ledger is the authority on whether money moved.
     */
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING   => 'Queued',
            self::COMPLETED => 'Sold',
            self::FAILED    => 'Failed',
        };
    }

    public function badgeColour(): string
    {
        return match ($this) {
            self::PENDING   => 'warning',
            self::COMPLETED => 'success',
            self::FAILED    => 'danger',
        };
    }

    /** Whether this status still blocks a second sale of the same club. */
    public function blocksResale(): bool
    {
        return $this !== self::FAILED;
    }
}
