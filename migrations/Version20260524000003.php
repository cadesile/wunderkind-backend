<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add facility construction time and failure config fields to facility_template and game_config';
    }

    public function up(Schema $schema): void
    {
        // FacilityTemplate — base construction weeks per facility type
        $this->addSql('ALTER TABLE facility_template ADD base_construction_weeks INTEGER DEFAULT 4 NOT NULL');

        // GameConfig — global construction time multiplier and failure mechanics (Feature 47a)
        $this->addSql('ALTER TABLE game_config ADD construction_time_multiplier DOUBLE PRECISION DEFAULT 1.0 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD facility_construction_failure_chance DOUBLE PRECISION DEFAULT 0.05 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD facility_construction_failure_cost_min INTEGER DEFAULT 50000 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD facility_construction_failure_cost_max INTEGER DEFAULT 200000 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD facility_construction_failure_time_min INTEGER DEFAULT 2 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD facility_construction_failure_time_max INTEGER DEFAULT 6 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facility_template DROP base_construction_weeks');
        $this->addSql('ALTER TABLE game_config DROP construction_time_multiplier');
        $this->addSql('ALTER TABLE game_config DROP facility_construction_failure_chance');
        $this->addSql('ALTER TABLE game_config DROP facility_construction_failure_cost_min');
        $this->addSql('ALTER TABLE game_config DROP facility_construction_failure_cost_max');
        $this->addSql('ALTER TABLE game_config DROP facility_construction_failure_time_min');
        $this->addSql('ALTER TABLE game_config DROP facility_construction_failure_time_max');
    }
}
