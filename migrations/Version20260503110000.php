<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update game_config defaults: reputationDelta, retirementAge/Chance to match frontend values';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config
            ALTER COLUMN reputation_delta_base SET DEFAULT 0.15,
            ALTER COLUMN reputation_delta_facility_multiplier SET DEFAULT 0.15,
            ALTER COLUMN retirement_min_age SET DEFAULT 30,
            ALTER COLUMN retirement_max_age SET DEFAULT 38,
            ALTER COLUMN retirement_chance SET DEFAULT 0.35
        ');

        $this->addSql('UPDATE game_config SET
            reputation_delta_base = 0.15,
            reputation_delta_facility_multiplier = 0.15,
            retirement_min_age = 30,
            retirement_max_age = 38,
            retirement_chance = 0.35
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config
            ALTER COLUMN reputation_delta_base SET DEFAULT 0.5,
            ALTER COLUMN reputation_delta_facility_multiplier SET DEFAULT 1.2,
            ALTER COLUMN retirement_min_age SET DEFAULT 16,
            ALTER COLUMN retirement_max_age SET DEFAULT 21,
            ALTER COLUMN retirement_chance SET DEFAULT 0.5
        ');

        $this->addSql('UPDATE game_config SET
            reputation_delta_base = 0.5,
            reputation_delta_facility_multiplier = 1.2,
            retirement_min_age = 16,
            retirement_max_age = 21,
            retirement_chance = 0.5
        ');
    }
}
