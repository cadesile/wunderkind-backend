<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260326222629 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD scout_morale_threshold INT NOT NULL DEFAULT 40');
        $this->addSql('ALTER TABLE game_config ADD scout_reveal_weeks INT NOT NULL DEFAULT 2');
        $this->addSql('ALTER TABLE game_config ADD scout_ability_error_range INT NOT NULL DEFAULT 30');
        $this->addSql('ALTER TABLE game_config ADD scout_max_assignments INT NOT NULL DEFAULT 5');
        $this->addSql("ALTER TABLE game_config ADD mission_gem_roll_thresholds JSON NOT NULL DEFAULT '[0.25, 0.75, 0.85, 0.94]'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config DROP scout_morale_threshold');
        $this->addSql('ALTER TABLE game_config DROP scout_reveal_weeks');
        $this->addSql('ALTER TABLE game_config DROP scout_ability_error_range');
        $this->addSql('ALTER TABLE game_config DROP scout_max_assignments');
        $this->addSql('ALTER TABLE game_config DROP mission_gem_roll_thresholds');
    }
}
