<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The ACCOUNT's opening credit range on `starter_config`.
 *
 * A DIFFERENT THING FROM starter_config.starting_balance, which already exists. That column is
 * the new CLUB's transfer kitty: device-owned, reassigned absolutely by every sync, spent on
 * players. This pair is the ACCOUNT's UserLedger credit — backend-owned, durable, and what land
 * and stadium purchases are actually charged against. A player has many clubs and exactly one
 * account balance, so the two cannot be the same column.
 *
 * A RANGE RATHER THAN A SINGLE VALUE so that the first real decision a player makes — which
 * town can I afford — is not identical for every account. The default span, £5,000,000 to
 * £6,000,000, is what the prototype's land step was built against.
 *
 * BIGINT, not INTEGER like starting_balance. £6,000,000 is 600,000,000 pence and fits a 4-byte
 * int today, but the ceiling is admin-editable and 4 bytes cap at £21,474,836 — exactly the
 * trap that forced Version20261103230000 to widen user_ledger's three money columns after a
 * test could not fund a £50M account. Not worth learning twice.
 *
 * NOT NULL with a DEFAULT applied in the same statement so existing rows (there is exactly one;
 * starter_config is a singleton keyed id = 1) take the defaults without a separate backfill.
 * The default is then dropped: the entity supplies it for new rows, and leaving a database
 * default would let an INSERT that forgot the column look correct.
 */
final class Version20261104140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "starter_config: the account's opening credit range (£5m-£6m default), distinct from the club kitty.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE starter_config
                 ADD user_starting_balance_min_pence BIGINT NOT NULL DEFAULT 500000000',
        );
        $this->addSql(
            'ALTER TABLE starter_config
                 ADD user_starting_balance_max_pence BIGINT NOT NULL DEFAULT 600000000',
        );

        $this->addSql('ALTER TABLE starter_config ALTER user_starting_balance_min_pence DROP DEFAULT');
        $this->addSql('ALTER TABLE starter_config ALTER user_starting_balance_max_pence DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE starter_config DROP COLUMN IF EXISTS user_starting_balance_min_pence');
        $this->addSql('ALTER TABLE starter_config DROP COLUMN IF EXISTS user_starting_balance_max_pence');
    }
}
