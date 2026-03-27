<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extend investor table: add tier, investment_amount, percentage_owned, invested_at, last_payout_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE investor
                ADD tier               VARCHAR(30)    NOT NULL DEFAULT 'angel',
                ADD investment_amount  INT UNSIGNED   NOT NULL DEFAULT 0,
                ADD percentage_owned   NUMERIC(5,2)   NOT NULL DEFAULT 5.00,
                ADD invested_at        DATETIME       DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                ADD last_payout_at     DATETIME       DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE investor DROP COLUMN tier, DROP COLUMN investment_amount, DROP COLUMN percentage_owned, DROP COLUMN invested_at, DROP COLUMN last_payout_at');
    }
}
