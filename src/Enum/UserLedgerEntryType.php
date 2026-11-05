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

    /**
     * Building out one facility site during club setup. ALL NEGATIVE.
     *
     * ONE CASE PER SITE, NOT ONE CASE WITH THE SITE IN `sourceRef`. The first draft did the
     * latter and it was wrong for a reason the backend could not see: the app ships an i18n key
     * per site, so a single `facility_build` left four of its 121 keys dead and every stand,
     * pitch and clinic labelled with whatever string the server happened to send. The type is
     * what the client localises on, so the type has to carry the site.
     *
     * Four fixed cases rather than a dynamic one because `UserLedgerEntryType` is a PHP enum
     * backing a stored column — it cannot grow at runtime. FACILITY_BUILD below is kept as the
     * FALLBACK for any category an admin adds later, so a fifth site still bills correctly (with
     * a generic label) instead of failing at the till. See ClubFacilityBuildService::ledgerType().
     */
    case FACILITY_TRAINING = 'facility_training';
    case FACILITY_MEDICAL  = 'facility_medical';
    case FACILITY_SCOUTING = 'facility_scouting';
    case FACILITY_STADIUM  = 'facility_stadium';

    /** A facility site with no case of its own — an admin-added category. NEGATIVE. */
    case FACILITY_BUILD = 'facility_build';

    /** The opening float the player puts into a club at setup. NEGATIVE. */
    case CLUB_FUNDING = 'club_funding';

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
            self::FACILITY_TRAINING => 'Training facilities',
            self::FACILITY_MEDICAL  => 'Medical facilities',
            self::FACILITY_SCOUTING => 'Scouting facilities',
            self::FACILITY_STADIUM  => 'Stadium',
            self::FACILITY_BUILD   => 'Facility build',
            self::CLUB_FUNDING     => 'Club funding',
            self::REVERSAL         => 'Reversal',
        };
    }

    /** Whether this kind of entry should normally reduce the balance. */
    public function isDebit(): bool
    {
        return match ($this) {
            self::LAND_PURCHASE,
            self::STADIUM_PURCHASE,
            self::FACILITY_TRAINING,
            self::FACILITY_MEDICAL,
            self::FACILITY_SCOUTING,
            self::FACILITY_STADIUM,
            self::FACILITY_BUILD,
            self::CLUB_FUNDING => true,
            default            => false,
        };
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
            self::LAND_PURCHASE, self::STADIUM_PURCHASE,
            self::FACILITY_TRAINING, self::FACILITY_MEDICAL,
            self::FACILITY_SCOUTING, self::FACILITY_STADIUM,
            self::FACILITY_BUILD, self::CLUB_FUNDING         => 'warning',
            self::REVERSAL                                  => 'danger',
        };
    }
}
