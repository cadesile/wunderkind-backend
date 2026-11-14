<?php

declare(strict_types=1);

namespace App\Message\Club;

/**
 * Execute one authorised club buyout.
 *
 * CARRIES THE SALE ID AND NOTHING ELSE — the house rule for every message in this codebase, and
 * load-bearing here rather than stylistic. The figure, the operator's note and the valuation's
 * working are already durable on the `club_sale` row before this is dispatched, so a duplicate
 * delivery is a no-op: the handler re-reads the row, and the ledger credit is idempotent on the
 * same id. An operator's authorisation of a payout must not live inside a Messenger envelope,
 * where it cannot be audited, corrected or re-read.
 *
 * Routed to the dedicated `clubsale` transport. Deliberately not `async`: a go-live batch is
 * thousands of these, and sharing a queue with push notifications would let one starve the other
 * — and a failure in destructive financial work should be isolated in its own `failed` listing.
 */
final class SellClubMessage
{
    public function __construct(
        public readonly string $saleId,
    ) {}
}
