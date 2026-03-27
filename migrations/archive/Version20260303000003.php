<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extend sponsor table: add monthly_payment, contract dates, reputation thresholds, bonus_multiplier, status, early_termination_fee';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE sponsor
                ADD monthly_payment              INT UNSIGNED  NOT NULL DEFAULT 0,
                ADD contract_start_date          DATETIME      DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                ADD contract_end_date            DATETIME      DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                ADD reputation_min_threshold     INT UNSIGNED  NOT NULL DEFAULT 0,
                ADD reputation_bonus_threshold   INT           DEFAULT NULL,
                ADD bonus_multiplier             NUMERIC(4,2)  NOT NULL DEFAULT 1.00,
                ADD status                       VARCHAR(30)   NOT NULL DEFAULT 'active',
                ADD early_termination_fee        INT           DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sponsor DROP COLUMN monthly_payment, DROP COLUMN contract_start_date, DROP COLUMN contract_end_date, DROP COLUMN reputation_min_threshold, DROP COLUMN reputation_bonus_threshold, DROP COLUMN bonus_multiplier, DROP COLUMN status, DROP COLUMN early_termination_fee');
    }
}
