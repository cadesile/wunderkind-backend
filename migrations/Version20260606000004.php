<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add youth_academy facility template';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "INSERT INTO facility_template
                (id, slug, label, description, category,
                 base_cost, weekly_upkeep_base, matchday_income, matchday_income_multiplier,
                 reputation_bonus, max_level, decay_base, gameplay_effects,
                 base_construction_weeks, sort_order, is_active, updated_at)
             VALUES
                (gen_random_uuid(),
                 'youth_academy',
                 'Youth Academy',
                 'Develops a pipeline of young talent for the club. Higher levels unlock larger annual youth intakes.',
                 'YOUTH',
                 250000,
                 5000,
                 NULL,
                 NULL,
                 0.05,
                 5,
                 2.0,
                 '{\"intakeCountByLevel\":[2,3,5,7,10,15],\"youthDevelopmentBonusPerLevel\":0.05}',
                 4,
                 50,
                 TRUE,
                 NOW())"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM facility_template WHERE slug = 'youth_academy'");
    }
}
