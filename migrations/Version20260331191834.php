<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260331191834 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config ADD guardian_convince_morale_boost INT NOT NULL DEFAULT 5');
        $this->addSql('ALTER TABLE game_config ADD guardian_convince_guardian_loyalty_boost INT NOT NULL DEFAULT 8');
        $this->addSql('ALTER TABLE game_config ADD guardian_convince_guardian_demand_increase INT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE game_config ADD guardian_ignore_morale_penalty INT NOT NULL DEFAULT 8');
        $this->addSql('ALTER TABLE game_config ADD guardian_ignore_loyalty_trait_penalty INT NOT NULL DEFAULT 3');
        $this->addSql('ALTER TABLE game_config ADD guardian_ignore_guardian_loyalty_penalty INT NOT NULL DEFAULT 12');
        $this->addSql('ALTER TABLE game_config ADD guardian_ignore_guardian_demand_increase INT NOT NULL DEFAULT 2');
        $this->addSql('ALTER TABLE game_config ADD guardian_ignore_sibling_morale_penalty INT NOT NULL DEFAULT 5');
        $this->addSql('ALTER TABLE game_config ADD guardian_ignore_sibling_loyalty_trait_penalty INT NOT NULL DEFAULT 2');
        $this->addSql('ALTER TABLE game_config ALTER guardian_convince_morale_boost DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER guardian_convince_guardian_loyalty_boost DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER guardian_convince_guardian_demand_increase DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER guardian_ignore_morale_penalty DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER guardian_ignore_loyalty_trait_penalty DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER guardian_ignore_guardian_loyalty_penalty DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER guardian_ignore_guardian_demand_increase DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER guardian_ignore_sibling_morale_penalty DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER guardian_ignore_sibling_loyalty_trait_penalty DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER incident_low_professionalism_threshold DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER incident_low_professionalism_chance DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER incident_high_determination_threshold DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER incident_high_determination_chance DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER incident_altercation_base_chance DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER incident_altercation_serious_base DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER incident_altercation_serious_temperament_scale DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config DROP guardian_convince_morale_boost');
        $this->addSql('ALTER TABLE game_config DROP guardian_convince_guardian_loyalty_boost');
        $this->addSql('ALTER TABLE game_config DROP guardian_convince_guardian_demand_increase');
        $this->addSql('ALTER TABLE game_config DROP guardian_ignore_morale_penalty');
        $this->addSql('ALTER TABLE game_config DROP guardian_ignore_loyalty_trait_penalty');
        $this->addSql('ALTER TABLE game_config DROP guardian_ignore_guardian_loyalty_penalty');
        $this->addSql('ALTER TABLE game_config DROP guardian_ignore_guardian_demand_increase');
        $this->addSql('ALTER TABLE game_config DROP guardian_ignore_sibling_morale_penalty');
        $this->addSql('ALTER TABLE game_config DROP guardian_ignore_sibling_loyalty_trait_penalty');
        $this->addSql('ALTER TABLE game_config ALTER incident_low_professionalism_threshold SET DEFAULT 6');
        $this->addSql('ALTER TABLE game_config ALTER incident_low_professionalism_chance SET DEFAULT \'0.3\'');
        $this->addSql('ALTER TABLE game_config ALTER incident_high_determination_threshold SET DEFAULT 15');
        $this->addSql('ALTER TABLE game_config ALTER incident_high_determination_chance SET DEFAULT \'0.25\'');
        $this->addSql('ALTER TABLE game_config ALTER incident_altercation_base_chance SET DEFAULT \'0.10\'');
        $this->addSql('ALTER TABLE game_config ALTER incident_altercation_serious_base SET DEFAULT \'0.2\'');
        $this->addSql('ALTER TABLE game_config ALTER incident_altercation_serious_temperament_scale SET DEFAULT \'0.5\'');
    }
}
