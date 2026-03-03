<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add age-out fields to player table: age_out_warning_issued, forced_sale_executed, forced_sale_week';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE player
                ADD age_out_warning_issued  TINYINT(1) NOT NULL DEFAULT 0,
                ADD forced_sale_executed    TINYINT(1) NOT NULL DEFAULT 0,
                ADD forced_sale_week        INT        DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE player DROP COLUMN age_out_warning_issued, DROP COLUMN forced_sale_executed, DROP COLUMN forced_sale_week');
    }
}
