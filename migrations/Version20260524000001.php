<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add manager_dividend_percent and transfer_budget_percent to game_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD manager_dividend_percent DOUBLE PRECISION DEFAULT 5 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD transfer_budget_percent DOUBLE PRECISION DEFAULT 20 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP manager_dividend_percent');
        $this->addSql('ALTER TABLE game_config DROP transfer_budget_percent');
    }
}
