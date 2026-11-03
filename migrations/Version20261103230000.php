<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widens `user_ledger`'s three money columns from INTEGER to BIGINT.
 *
 * REQUIRED, not defensive. They were 4-byte integers, which cap at 2,147,483,647 pence —
 * £21,474,836. The land-and-stadium economy is priced on real-world values
 * (StadiumEstimateService uses ~£1,500 per seat, so a 90,000-seat London ground is ~£135M, and
 * Birmingham's is ~£69M), and an account-level balance exists precisely so that earnings
 * ACCUMULATE across ten to fifteen clubs on the way to affording one. So both a single
 * transaction and the running total routinely exceed the old ceiling: the ledger was physically
 * unable to record a flagship purchase, and would have failed with
 * "value out of range for type integer" at the moment a player tried to buy.
 *
 * Found by PlotHoldAndClaimTest, which funds a test account with £50M and could not write the
 * grant.
 *
 * SAFE IN BOTH DIRECTIONS AT PRESENT. INTEGER -> BIGINT is a widening in-place rewrite that
 * cannot lose data. The down() narrows again, which WOULD fail on any row already exceeding the
 * old range — deliberately left to fail loudly rather than silently truncating money.
 *
 * Note the PHP properties stay typed `int`: PHP integers are 64-bit on every platform this runs
 * on, so the only change needed on the entity was casting the getters, since Doctrine hydrates a
 * bigint column as a string.
 */
final class Version20261103230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user_ledger money columns INTEGER -> BIGINT: real-world land and stadium prices exceed the 4-byte cap (£21.5M).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_ledger ALTER amount_pence TYPE BIGINT');
        $this->addSql('ALTER TABLE user_ledger ALTER balance_before_pence TYPE BIGINT');
        $this->addSql('ALTER TABLE user_ledger ALTER balance_after_pence TYPE BIGINT');
    }

    public function down(Schema $schema): void
    {
        // Narrowing. This will fail rather than truncate if any row is out of int4 range, which
        // is the correct behaviour for money.
        $this->addSql('ALTER TABLE user_ledger ALTER amount_pence TYPE INT');
        $this->addSql('ALTER TABLE user_ledger ALTER balance_before_pence TYPE INT');
        $this->addSql('ALTER TABLE user_ledger ALTER balance_after_pence TYPE INT');
    }
}
