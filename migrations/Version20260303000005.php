<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add financial_year_start to academy table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE academy ADD financial_year_start TINYINT UNSIGNED NOT NULL DEFAULT 4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE academy DROP COLUMN financial_year_start');
    }
}
