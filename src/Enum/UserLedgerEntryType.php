<?php

namespace App\Enum;

/**
 * UserLedger entry kinds. Only DIVIDEND_DRAW exists today (a client-reported draw against
 * one of the user's clubs, detected in the sync payload's free-form ledger — see
 * UserLedgerService); the type column exists so the same centralized, cross-club ledger can
 * carry other kinds of entry later without a schema change.
 */
enum UserLedgerEntryType: string
{
    case DIVIDEND_DRAW = 'dividend_draw';
}
