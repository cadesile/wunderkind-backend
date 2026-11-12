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
 * the account it was meant to debit. Two cases opt out and carry either sign per row —
 * REVERSAL, which has to undo whatever it targets, and CUSTOM_INCOME, which is a human
 * adjustment that may credit or deduct. Both are therefore absent from isDebit(); see its note.
 *
 * Adding a case is free of migrations — the column is varchar(30) and the longest value here is
 * 17 characters (`facility_training`), asserted in UserLedgerEntryTypeTest so a new case cannot
 * silently overflow it.
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

    /**
     * The owner gifting money from their own account into one of their clubs. NEGATIVE.
     *
     * ONCE PER CLUB PER SEASON, up to a per-tier ceiling. Idempotency is the ledger's
     * `(sourceKind, sourceRef, type)` unique index with `sourceRef = "{clubId}:{season}"` — the
     * same natural-key approach CLUB_FUNDING uses, rather than an Idempotency-Key header,
     * because the thing being protected already has a natural key.
     *
     * THE CLUB NEVER RECEIVES THIS SERVER-SIDE, which is the part worth knowing. The debit from
     * the owner's ledger is real and durable; the credit to `club.balance` is an INSTRUCTION the
     * response carries and the device applies. `SyncService::process()` overwrites
     * `club.balance` absolutely from the device on every sync, so a server-side credit there
     * would survive until the next sync and then vanish — the trap ClubFundingService documents
     * at length.
     *
     * A DEBIT, so `isDebit()` returns true and `append()` refuses a positive amount. Unlike
     * CUSTOM_INCOME this has one fixed direction: money only ever leaves the account.
     */
    case SEASON_INVESTMENT = 'season_investment';

    /**
     * A manual adjustment made by an admin. EITHER SIGN.
     *
     * The only type a human authors directly — a goodwill credit, a compensation, a correction.
     * Written through the normal service path from the admin's "New Adjustment" form, never by
     * hand-editing a row: the balance chain still has to be computed in one place.
     *
     * DELIBERATELY NOT LISTED IN isDebit(), and that is load-bearing rather than an oversight.
     * UserLedgerService::append() throws when isDebit() is true and the amount is positive, so
     * marking this a debit would make it impossible to CREDIT an account — the common case. The
     * sign is per row here, which no other type does, so neither answer from isDebit() is right
     * for all of them and the permissive one is the only one that works.
     *
     * Its label is player-visible: GET /api/account/ledger returns label() alongside the
     * operator's own description text.
     */
    case CUSTOM_INCOME = 'custom_income';

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
            self::SEASON_INVESTMENT => 'Season investment',
            // PLAYER-VISIBLE. Returned as `label` by GET /api/account/ledger, so it is worded
            // for the person reading their own ledger, not for the admin who typed it — and it
            // has to read sensibly whether the amount is a credit or a deduction.
            self::CUSTOM_INCOME    => 'Adjustment',
            self::REVERSAL         => 'Reversal',
        };
    }

    /**
     * Whether this kind of entry should normally reduce the balance.
     *
     * CUSTOM_INCOME AND REVERSAL ARE DELIBERATELY ABSENT, and that is not an oversight to be
     * tidied up. UserLedgerService::append() throws when this returns true and the amount is
     * positive — a guard that only makes sense for a type whose direction is fixed. Both of
     * these carry either sign per row, so listing them here would make it impossible to CREDIT
     * an account, which for CUSTOM_INCOME is the common case. tests/Enum/UserLedgerEntryTypeTest
     * asserts the exact debit list precisely so this stays true.
     */
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
            self::CLUB_FUNDING,
            self::SEASON_INVESTMENT => true,
            default                 => false,
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
            self::FACILITY_BUILD, self::CLUB_FUNDING,
            self::SEASON_INVESTMENT                         => 'warning',
            // Neutral on purpose: the sign varies per row, so no one colour is honest about
            // direction. The amount column already shows it.
            self::CUSTOM_INCOME                             => 'info',
            self::REVERSAL                                  => 'danger',
        };
    }
}
