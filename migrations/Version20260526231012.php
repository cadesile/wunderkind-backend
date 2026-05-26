<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260526231012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE club ALTER reputation TYPE BIGINT');
        $this->addSql('ALTER TABLE club ALTER hall_of_fame_points TYPE BIGINT');
        $this->addSql('ALTER TABLE club ALTER balance TYPE BIGINT');
        $this->addSql('ALTER TABLE club ALTER starter_initialized_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE country_world_pack_cache ALTER payload TYPE JSON');
        $this->addSql('ALTER TABLE country_world_pack_cache ALTER generated_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE facility_template ALTER base_construction_weeks DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER potential_overshoot_max DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER potential_decay_rate DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER construction_time_multiplier DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER facility_construction_failure_chance DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER facility_construction_failure_cost_min DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER facility_construction_failure_cost_max DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER facility_construction_failure_time_min DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER facility_construction_failure_time_max DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER yellow_card_base_chance DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER red_card_base_chance DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER yellow_card_accumulation_threshold DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER red_card_suspension_matches DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER temperament_card_scale DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER coach_development_max_multiplier DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER coach_development_min_specialism DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER coach_development_stacking_factor DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER coach_morale_influence DROP DEFAULT');
        $this->addSql('ALTER TABLE match_result ALTER yellow_cards DROP DEFAULT');
        $this->addSql('ALTER TABLE match_result ALTER red_cards DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE club ALTER reputation TYPE INT');
        $this->addSql('ALTER TABLE club ALTER hall_of_fame_points TYPE INT');
        $this->addSql('ALTER TABLE club ALTER starter_initialized_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE club ALTER balance TYPE INT');
        $this->addSql('ALTER TABLE country_world_pack_cache ALTER payload TYPE JSONB');
        $this->addSql('ALTER TABLE country_world_pack_cache ALTER generated_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE facility_template ALTER base_construction_weeks SET DEFAULT 4');
        $this->addSql('ALTER TABLE game_config ALTER potential_overshoot_max SET DEFAULT \'0.05\'');
        $this->addSql('ALTER TABLE game_config ALTER potential_decay_rate SET DEFAULT \'0.5\'');
        $this->addSql('ALTER TABLE game_config ALTER coach_development_max_multiplier SET DEFAULT \'2.0\'');
        $this->addSql('ALTER TABLE game_config ALTER coach_development_min_specialism SET DEFAULT 20');
        $this->addSql('ALTER TABLE game_config ALTER coach_development_stacking_factor SET DEFAULT \'0.3\'');
        $this->addSql('ALTER TABLE game_config ALTER coach_morale_influence SET DEFAULT \'0.5\'');
        $this->addSql('ALTER TABLE game_config ALTER construction_time_multiplier SET DEFAULT \'1.0\'');
        $this->addSql('ALTER TABLE game_config ALTER facility_construction_failure_chance SET DEFAULT \'0.05\'');
        $this->addSql('ALTER TABLE game_config ALTER facility_construction_failure_cost_min SET DEFAULT \'5.0\'');
        $this->addSql('ALTER TABLE game_config ALTER facility_construction_failure_cost_max SET DEFAULT \'20.0\'');
        $this->addSql('ALTER TABLE game_config ALTER facility_construction_failure_time_min SET DEFAULT 2');
        $this->addSql('ALTER TABLE game_config ALTER facility_construction_failure_time_max SET DEFAULT 6');
        $this->addSql('ALTER TABLE game_config ALTER yellow_card_base_chance SET DEFAULT \'0.08\'');
        $this->addSql('ALTER TABLE game_config ALTER red_card_base_chance SET DEFAULT \'0.01\'');
        $this->addSql('ALTER TABLE game_config ALTER yellow_card_accumulation_threshold SET DEFAULT 5');
        $this->addSql('ALTER TABLE game_config ALTER red_card_suspension_matches SET DEFAULT 1');
        $this->addSql('ALTER TABLE game_config ALTER temperament_card_scale SET DEFAULT \'1.0\'');
        $this->addSql('ALTER TABLE match_result ALTER yellow_cards SET DEFAULT 0');
        $this->addSql('ALTER TABLE match_result ALTER red_cards SET DEFAULT 0');
    }
}
