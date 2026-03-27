<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260326234223 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE admin DROP CONSTRAINT fk_880e0d76a76ed395');
        $this->addSql('DROP INDEX uniq_880e0d76a76ed395');
        $this->addSql('ALTER TABLE admin ADD email VARCHAR(180) NOT NULL');
        $this->addSql('ALTER TABLE admin ADD password VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE admin ADD name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE admin DROP user_id');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_880E0D76E7927C74 ON admin (email)');
        $this->addSql('ALTER TABLE game_config ALTER scout_morale_threshold DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER scout_reveal_weeks DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER scout_ability_error_range DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER scout_max_assignments DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER mission_gem_roll_thresholds DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER player_age_min DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER player_age_max DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER player_potential_min DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER player_potential_max DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER player_potential_mean DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER player_ability_min DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER player_ability_max DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER player_attribute_budget_min DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER player_attribute_budget_max DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER player_agent_chance_percent DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER player_height_min DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER player_height_max DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER player_weight_min DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER player_weight_max DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER personality_trait_min DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER personality_trait_max DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER position_weight_gk DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER position_weight_def DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER position_weight_mid DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER position_weight_att DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER coach_age_min DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER coach_age_max DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER coach_ability_min DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER coach_ability_max DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER scout_age_min DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER scout_age_max DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER scout_experience_min DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER scout_experience_max DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER scout_judgement_min DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER scout_judgement_max DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER agent_reputation_min DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER agent_reputation_max DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER agent_age_min DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER agent_age_max DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER player_pool_target DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER coach_pool_target DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER scout_pool_target DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER sponsor_pool_target DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER investor_pool_target DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_880E0D76E7927C74');
        $this->addSql('ALTER TABLE admin ADD user_id UUID NOT NULL');
        $this->addSql('ALTER TABLE admin DROP email');
        $this->addSql('ALTER TABLE admin DROP password');
        $this->addSql('ALTER TABLE admin DROP name');
        $this->addSql('ALTER TABLE admin ADD CONSTRAINT fk_880e0d76a76ed395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX uniq_880e0d76a76ed395 ON admin (user_id)');
        $this->addSql('ALTER TABLE game_config ALTER scout_morale_threshold SET DEFAULT 40');
        $this->addSql('ALTER TABLE game_config ALTER scout_reveal_weeks SET DEFAULT 2');
        $this->addSql('ALTER TABLE game_config ALTER scout_ability_error_range SET DEFAULT 30');
        $this->addSql('ALTER TABLE game_config ALTER scout_max_assignments SET DEFAULT 5');
        $this->addSql('ALTER TABLE game_config ALTER mission_gem_roll_thresholds SET DEFAULT \'[0.25, 0.75, 0.85, 0.94]\'');
        $this->addSql('ALTER TABLE pool_config ALTER player_age_min SET DEFAULT 12');
        $this->addSql('ALTER TABLE pool_config ALTER player_age_max SET DEFAULT 13');
        $this->addSql('ALTER TABLE pool_config ALTER player_potential_min SET DEFAULT 40');
        $this->addSql('ALTER TABLE pool_config ALTER player_potential_max SET DEFAULT 80');
        $this->addSql('ALTER TABLE pool_config ALTER player_potential_mean SET DEFAULT 60');
        $this->addSql('ALTER TABLE pool_config ALTER player_ability_min SET DEFAULT 3');
        $this->addSql('ALTER TABLE pool_config ALTER player_ability_max SET DEFAULT 10');
        $this->addSql('ALTER TABLE pool_config ALTER player_attribute_budget_min SET DEFAULT 6');
        $this->addSql('ALTER TABLE pool_config ALTER player_attribute_budget_max SET DEFAULT 20');
        $this->addSql('ALTER TABLE pool_config ALTER player_agent_chance_percent SET DEFAULT 40');
        $this->addSql('ALTER TABLE pool_config ALTER player_height_min SET DEFAULT 145');
        $this->addSql('ALTER TABLE pool_config ALTER player_height_max SET DEFAULT 160');
        $this->addSql('ALTER TABLE pool_config ALTER player_weight_min SET DEFAULT 38');
        $this->addSql('ALTER TABLE pool_config ALTER player_weight_max SET DEFAULT 55');
        $this->addSql('ALTER TABLE pool_config ALTER personality_trait_min SET DEFAULT 30');
        $this->addSql('ALTER TABLE pool_config ALTER personality_trait_max SET DEFAULT 70');
        $this->addSql('ALTER TABLE pool_config ALTER position_weight_gk SET DEFAULT 8');
        $this->addSql('ALTER TABLE pool_config ALTER position_weight_def SET DEFAULT 30');
        $this->addSql('ALTER TABLE pool_config ALTER position_weight_mid SET DEFAULT 38');
        $this->addSql('ALTER TABLE pool_config ALTER position_weight_att SET DEFAULT 24');
        $this->addSql('ALTER TABLE pool_config ALTER coach_age_min SET DEFAULT 28');
        $this->addSql('ALTER TABLE pool_config ALTER coach_age_max SET DEFAULT 60');
        $this->addSql('ALTER TABLE pool_config ALTER coach_ability_min SET DEFAULT 40');
        $this->addSql('ALTER TABLE pool_config ALTER coach_ability_max SET DEFAULT 75');
        $this->addSql('ALTER TABLE pool_config ALTER scout_age_min SET DEFAULT 28');
        $this->addSql('ALTER TABLE pool_config ALTER scout_age_max SET DEFAULT 40');
        $this->addSql('ALTER TABLE pool_config ALTER scout_experience_min SET DEFAULT 0');
        $this->addSql('ALTER TABLE pool_config ALTER scout_experience_max SET DEFAULT 10');
        $this->addSql('ALTER TABLE pool_config ALTER scout_judgement_min SET DEFAULT 40');
        $this->addSql('ALTER TABLE pool_config ALTER scout_judgement_max SET DEFAULT 80');
        $this->addSql('ALTER TABLE pool_config ALTER agent_reputation_min SET DEFAULT 30');
        $this->addSql('ALTER TABLE pool_config ALTER agent_reputation_max SET DEFAULT 70');
        $this->addSql('ALTER TABLE pool_config ALTER agent_age_min SET DEFAULT 30');
        $this->addSql('ALTER TABLE pool_config ALTER agent_age_max SET DEFAULT 60');
        $this->addSql('ALTER TABLE pool_config ALTER player_pool_target SET DEFAULT 50');
        $this->addSql('ALTER TABLE pool_config ALTER coach_pool_target SET DEFAULT 10');
        $this->addSql('ALTER TABLE pool_config ALTER scout_pool_target SET DEFAULT 5');
        $this->addSql('ALTER TABLE pool_config ALTER sponsor_pool_target SET DEFAULT 10');
        $this->addSql('ALTER TABLE pool_config ALTER investor_pool_target SET DEFAULT 5');
    }
}
