<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change facility construction failure cost fields from pence integers to % floats';
    }

    public function up(Schema $schema): void
    {
        // Retype from INTEGER (pence) to DOUBLE PRECISION (% of upgrade cost)
        $this->addSql('ALTER TABLE game_config ALTER COLUMN facility_construction_failure_cost_min TYPE DOUBLE PRECISION USING 5.0');
        $this->addSql('ALTER TABLE game_config ALTER COLUMN facility_construction_failure_cost_max TYPE DOUBLE PRECISION USING 20.0');
        $this->addSql('ALTER TABLE game_config ALTER COLUMN facility_construction_failure_cost_min SET DEFAULT 5.0');
        $this->addSql('ALTER TABLE game_config ALTER COLUMN facility_construction_failure_cost_max SET DEFAULT 20.0');
        // Reset any existing rows to the new percentage defaults
        $this->addSql('UPDATE game_config SET facility_construction_failure_cost_min = 5.0, facility_construction_failure_cost_max = 20.0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ALTER COLUMN facility_construction_failure_cost_min TYPE INTEGER USING 50000');
        $this->addSql('ALTER TABLE game_config ALTER COLUMN facility_construction_failure_cost_max TYPE INTEGER USING 200000');
        $this->addSql('ALTER TABLE game_config ALTER COLUMN facility_construction_failure_cost_min SET DEFAULT 50000');
        $this->addSql('ALTER TABLE game_config ALTER COLUMN facility_construction_failure_cost_max SET DEFAULT 200000');
        $this->addSql('UPDATE game_config SET facility_construction_failure_cost_min = 50000, facility_construction_failure_cost_max = 200000');
    }
}
