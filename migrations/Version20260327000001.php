<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260327000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create pool_config table for configurable market pool generation parameters';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('pool_config');
        $table->addColumn('id',                          'integer', ['autoincrement' => true]);
        $table->addColumn('player_age_min',              'integer', ['default' => 12]);
        $table->addColumn('player_age_max',              'integer', ['default' => 13]);
        $table->addColumn('player_potential_min',        'integer', ['default' => 40]);
        $table->addColumn('player_potential_max',        'integer', ['default' => 80]);
        $table->addColumn('player_potential_mean',       'integer', ['default' => 60]);
        $table->addColumn('player_ability_min',          'integer', ['default' => 3]);
        $table->addColumn('player_ability_max',          'integer', ['default' => 10]);
        $table->addColumn('player_attribute_budget_min', 'integer', ['default' => 6]);
        $table->addColumn('player_attribute_budget_max', 'integer', ['default' => 20]);
        $table->addColumn('player_agent_chance_percent', 'integer', ['default' => 40]);
        $table->addColumn('player_height_min',           'integer', ['default' => 145]);
        $table->addColumn('player_height_max',           'integer', ['default' => 160]);
        $table->addColumn('player_weight_min',           'integer', ['default' => 38]);
        $table->addColumn('player_weight_max',           'integer', ['default' => 55]);
        $table->addColumn('personality_trait_min',       'integer', ['default' => 30]);
        $table->addColumn('personality_trait_max',       'integer', ['default' => 70]);
        $table->addColumn('position_weight_gk',          'integer', ['default' => 8]);
        $table->addColumn('position_weight_def',         'integer', ['default' => 30]);
        $table->addColumn('position_weight_mid',         'integer', ['default' => 38]);
        $table->addColumn('position_weight_att',         'integer', ['default' => 24]);
        $table->addColumn('coach_age_min',               'integer', ['default' => 28]);
        $table->addColumn('coach_age_max',               'integer', ['default' => 60]);
        $table->addColumn('coach_ability_min',           'integer', ['default' => 40]);
        $table->addColumn('coach_ability_max',           'integer', ['default' => 75]);
        $table->addColumn('scout_age_min',               'integer', ['default' => 28]);
        $table->addColumn('scout_age_max',               'integer', ['default' => 40]);
        $table->addColumn('scout_experience_min',        'integer', ['default' => 0]);
        $table->addColumn('scout_experience_max',        'integer', ['default' => 10]);
        $table->addColumn('scout_judgement_min',         'integer', ['default' => 40]);
        $table->addColumn('scout_judgement_max',         'integer', ['default' => 80]);
        $table->addColumn('agent_reputation_min',        'integer', ['default' => 30]);
        $table->addColumn('agent_reputation_max',        'integer', ['default' => 70]);
        $table->addColumn('agent_age_min',               'integer', ['default' => 30]);
        $table->addColumn('agent_age_max',               'integer', ['default' => 60]);
        $table->addColumn('player_pool_target',          'integer', ['default' => 50]);
        $table->addColumn('coach_pool_target',           'integer', ['default' => 10]);
        $table->addColumn('scout_pool_target',           'integer', ['default' => 5]);
        $table->addColumn('sponsor_pool_target',         'integer', ['default' => 10]);
        $table->addColumn('investor_pool_target',        'integer', ['default' => 5]);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('pool_config');
    }
}
