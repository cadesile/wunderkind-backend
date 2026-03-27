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
    }
}
