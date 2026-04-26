<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260426000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add starter staff count fields to starter_config (manager, director_of_football, facility_manager, chairman)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE starter_config ADD COLUMN starter_manager_count INTEGER NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE starter_config ADD COLUMN starter_director_of_football_count INTEGER NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE starter_config ADD COLUMN starter_facility_manager_count INTEGER NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE starter_config ADD COLUMN starter_chairman_count INTEGER NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE starter_config DROP COLUMN starter_chairman_count');
        $this->addSql('ALTER TABLE starter_config DROP COLUMN starter_facility_manager_count');
        $this->addSql('ALTER TABLE starter_config DROP COLUMN starter_director_of_football_count');
        $this->addSql('ALTER TABLE starter_config DROP COLUMN starter_manager_count');
    }
}
