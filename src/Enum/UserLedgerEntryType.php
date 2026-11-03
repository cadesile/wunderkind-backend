<?php

namespace App\Enum;

/**
 * UserLedger entry kinds.
 *
 * DIVIDEND_DRAW is the original and the only one sourced from a sync payload: a client-reported
 * draw against one of the user's clubs, detected in the free-form ledger array (see
 * UserLedgerService). Everything else is backend-originated and written through
 * UserLedgerService::append().
 *
 * THE SIGN CONVENTION IS LOAD-BEARING. `amountPence` is signed and
 * `balanceAfterPence = balanceBeforePence + amountPence` is a constructor invariant, so
 * purchases MUST be negative and credits positive. A purchase recorded positive would credit
 * the account it was meant to debit.
 *
 * Adding a case is free of migrations — the column is varchar(30) and the longest value here is
 * 16 characters (asserted in UserLedgerEntryTypeTest, so case nine cannot silently overflow it).
 * What is NOT free is the effect on aggregates: UserLedgerRepository's dividend sums and the
 * landing page's Rich List both read this table, and both filter on type precisely because a
 * negative purchase row would otherwise corrupt a public leaderboard. Read that repository
 * before adding a case.
 */
enum UserLedgerEntryType: string
{
    /** Client-reported dividend draw, from a sync payload. Positive. */
    case DIVIDEND_DRAW = 'dividend_draw';

    /** One-off credit granted to a new account. Positive. */
    case STARTING_GRANT = 'starting_grant';

    /** Credit earned from the onboarding quiz. Positive. */
    case QUIZ_BONUS = 'quiz_bonus';

    /** Buying a land plot. NEGATIVE. */
    case LAND_PURCHASE = 'land_purchase';

    /** Buying a stadium on a claimed plot. NEGATIVE. */
    case STADIUM_PURCHASE = 'stadium_purchase';

    /** Proceeds of selling a plot back. Positive. */
    case SELL_BACK_CREDIT = 'sell_back_credit';

    /** Support reversal of an earlier entry. Carries whatever sign undoes its target. */
    case REVERSAL = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::DIVIDEND_DRAW    => 'Dividend draw',
            self::STARTING_GRANT   => 'Starting grant',
            self::QUIZ_BONUS       => 'Quiz bonus',
            self::LAND_PURCHASE    => 'Land purchase',
            self::STADIUM_PURCHASE => 'Stadium purchase',
            self::SELL_BACK_CREDIT => 'Sell-back credit',
            self::REVERSAL         => 'Reversal',
        };
    }

    /** Whether this kind of entry should normally reduce the balance. */
    public function isDebit(): bool
    {
        return $this === self::LAND_PURCHASE || $this === self::STADIUM_PURCHASE;
    }

    /**
     * Whether this entry counts as club earnings.
     *
     * Only DIVIDEND_DRAW does. This is the predicate the repository's dividend aggregates and
     * the public career-earnings leaderboard are built on — a land purchase is money LEAVING
     * the account, and summing it into "earnings" would make the leaderboard wrong.
     */
    public function isClubEarnings(): bool
    {
        return $this === self::DIVIDEND_DRAW;
    }

    public function badgeColour(): string
    {
        return match ($this) {
            self::DIVIDEND_DRAW, self::SELL_BACK_CREDIT     => 'success',
            self::STARTING_GRANT, self::QUIZ_BONUS          => 'info',
            self::LAND_PURCHASE, self::STADIUM_PURCHASE     => 'warning',
            self::REVERSAL                                  => 'danger',
        };
    }
}
