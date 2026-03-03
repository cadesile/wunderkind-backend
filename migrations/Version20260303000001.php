<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wage reduction: divide existing staff weekly_salary and player contract_value by 10';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE staff SET weekly_salary = GREATEST(0, ROUND(weekly_salary / 10))');
        $this->addSql('UPDATE player SET contract_value = GREATEST(0, ROUND(contract_value / 10))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE staff SET weekly_salary = weekly_salary * 10');
        $this->addSql('UPDATE player SET contract_value = contract_value * 10');
    }
}
