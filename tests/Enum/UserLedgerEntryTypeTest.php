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

    /** Purchases are the debits, and they are the ones that must be stored negative. */
    public function testOnlyPurchasesAreDebits(): void
    {
        $debits = array_map(
            static fn (UserLedgerEntryType $t): string => $t->value,
            array_values(array_filter(
                UserLedgerEntryType::cases(),
                static fn (UserLedgerEntryType $t): bool => $t->isDebit(),
            )),
        );

        self::assertSame(['land_purchase', 'stadium_purchase'], $debits);
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
    }
}
