<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\UserLedgerEntryType;
use PHPUnit\Framework\TestCase;

/**
 * Guards the two things about this enum that break quietly.
 */
class UserLedgerEntryTypeTest extends TestCase
{
    /**
     * Every value fits the column.
     *
     * `user_ledger.type` is varchar(30). Adding a case needs no migration while that holds, so
     * this test is what stops case nine overflowing it and failing at insert time instead.
     */
    public function testEveryValueFitsTheColumn(): void
    {
        foreach (UserLedgerEntryType::cases() as $case) {
            self::assertLessThanOrEqual(
                30,
                strlen($case->value),
                "'{$case->value}' is too long for user_ledger.type (varchar(30))",
            );
        }
    }

    /**
     * Exactly one type counts as club earnings.
     *
     * The public career-earnings leaderboard sums this table filtered on that predicate. If a
     * second type ever starts counting — especially a negative one — the leaderboard changes
     * meaning, so that has to be a deliberate edit to this test rather than a side effect.
     */
    public function testOnlyDividendDrawCountsAsClubEarnings(): void
    {
        $earning = array_values(array_filter(
            UserLedgerEntryType::cases(),
            static fn (UserLedgerEntryType $t): bool => $t->isClubEarnings(),
        ));

        self::assertSame([UserLedgerEntryType::DIVIDEND_DRAW], $earning);
    }

    /**
     * Purchases and setup spends are the debits, and they are the ones that must be stored
     * negative.
     *
     * Deliberately exhaustive rather than a predicate check: UserLedgerService::append() throws
     * on a positive debit, so moving a case in or out of this list changes which writes are
     * rejected. The four per-site FACILITY_* cases, their FACILITY_BUILD fallback and
     * CLUB_FUNDING joined it with the setup endpoints. The per-site split exists because the
     * client localises a ledger row on its type and ships one i18n key per site.
     *
     * SEASON_INVESTMENT joined 2026-11-12. It is a debit with ONE fixed direction — money only
     * ever leaves the owner's account — which is what separates it from CUSTOM_INCOME, the one
     * type that is deliberately absent from this list because its sign varies per row.
     */
    public function testOnlyPurchasesAreDebits(): void
    {
        $debits = array_map(
            static fn (UserLedgerEntryType $t): string => $t->value,
            array_values(array_filter(
                UserLedgerEntryType::cases(),
                static fn (UserLedgerEntryType $t): bool => $t->isDebit(),
            )),
        );

        self::assertSame(
            [
                'land_purchase',
                'stadium_purchase',
                'facility_training',
                'facility_medical',
                'facility_scouting',
                'facility_stadium',
                'facility_build',
                'club_funding',
                'season_investment',
            ],
            $debits,
        );
    }

    public function testEveryCaseHasALabelAndABadgeColour(): void
    {
        foreach (UserLedgerEntryType::cases() as $case) {
            self::assertNotSame('', $case->label(), "{$case->value} has no label");
            self::assertNotSame('', $case->badgeColour(), "{$case->value} has no badge colour");
        }
    }

    /** Values are stored in the database, so they are immutable once shipped. */
    public function testStoredValuesAreUnchanged(): void
    {
        self::assertSame('dividend_draw', UserLedgerEntryType::DIVIDEND_DRAW->value);
        self::assertSame('custom_income', UserLedgerEntryType::CUSTOM_INCOME->value);
    }

    /**
     * CUSTOM_INCOME must never be marked a debit.
     *
     * The debit list above already proves it by omission, but only to someone who notices the
     * absence. This says it out loud, because the omission looks exactly like an oversight and
     * the consequence of "fixing" it is severe: UserLedgerService::append() throws when a debit
     * type carries a positive amount, so listing CUSTOM_INCOME there would make it impossible to
     * CREDIT an account — which is the main thing the admin adjustment form is for.
     *
     * The type carries either sign per row, which is why no fixed answer from isDebit() is right
     * for it and the permissive one is the only workable choice.
     */
    public function testCustomIncomeIsNotADebitSoEitherSignIsAccepted(): void
    {
        self::assertFalse(
            UserLedgerEntryType::CUSTOM_INCOME->isDebit(),
            'marking custom_income a debit would make UserLedgerService::append() reject every credit',
        );
    }

    /**
     * Its label is player-visible, so it is checked rather than left to drift.
     *
     * GET /api/account/ledger returns `label` for every row the player reads back, so this
     * string lands in front of a person who has no idea an admin typed it — and it has to read
     * sensibly whether the row credits or deducts.
     */
    public function testCustomIncomeReadsSensiblyToAPlayer(): void
    {
        self::assertSame('Adjustment', UserLedgerEntryType::CUSTOM_INCOME->label());
    }
}
