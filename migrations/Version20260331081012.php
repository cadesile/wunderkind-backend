<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260331081012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add incident tuning fields to game_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD incident_low_professionalism_threshold INT NOT NULL DEFAULT 6');
        $this->addSql('ALTER TABLE game_config ADD incident_low_professionalism_chance DOUBLE PRECISION NOT NULL DEFAULT 0.3');
        $this->addSql('ALTER TABLE game_config ADD incident_high_determination_threshold INT NOT NULL DEFAULT 15');
        $this->addSql('ALTER TABLE game_config ADD incident_high_determination_chance DOUBLE PRECISION NOT NULL DEFAULT 0.25');
        $this->addSql('ALTER TABLE game_config ADD incident_altercation_base_chance DOUBLE PRECISION NOT NULL DEFAULT 0.10');
        $this->addSql('ALTER TABLE game_config ADD incident_altercation_serious_base DOUBLE PRECISION NOT NULL DEFAULT 0.2');
        $this->addSql('ALTER TABLE game_config ADD incident_altercation_serious_temperament_scale DOUBLE PRECISION NOT NULL DEFAULT 0.5');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config DROP incident_low_professionalism_threshold');
        $this->addSql('ALTER TABLE game_config DROP incident_low_professionalism_chance');
        $this->addSql('ALTER TABLE game_config DROP incident_high_determination_threshold');
        $this->addSql('ALTER TABLE game_config DROP incident_high_determination_chance');
        $this->addSql('ALTER TABLE game_config DROP incident_altercation_base_chance');
        $this->addSql('ALTER TABLE game_config DROP incident_altercation_serious_base');
        $this->addSql('ALTER TABLE game_config DROP incident_altercation_serious_temperament_scale');
    }
}
