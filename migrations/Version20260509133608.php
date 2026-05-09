<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260509133608 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fan base ranges + promotion/relegation deltas to starter_config; add manager sacking thresholds to game_config';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config ADD manager_sacking_win_ratio_trigger DOUBLE PRECISION DEFAULT 0.2 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD manager_sacking_win_ratio_recovery DOUBLE PRECISION DEFAULT 0.25 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD manager_sacking_min_games INT DEFAULT 30 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD manager_sacking_attendance_penalty_per_week DOUBLE PRECISION DEFAULT 0.05 NOT NULL');
        $this->addSql("ALTER TABLE starter_config ADD fan_base_ranges JSON NOT NULL DEFAULT '{\"1\":{\"min\":50000,\"max\":150000},\"2\":{\"min\":20000,\"max\":70000},\"3\":{\"min\":10000,\"max\":30000},\"4\":{\"min\":5000,\"max\":15000},\"5\":{\"min\":2000,\"max\":8000},\"6\":{\"min\":1000,\"max\":4000},\"7\":{\"min\":500,\"max\":2000},\"8\":{\"min\":200,\"max\":1000}}'");
        $this->addSql('ALTER TABLE starter_config ALTER COLUMN fan_base_ranges DROP DEFAULT');
        $this->addSql('ALTER TABLE starter_config ADD fan_base_promotion_increase DOUBLE PRECISION DEFAULT 0.2 NOT NULL');
        $this->addSql('ALTER TABLE starter_config ADD fan_base_relegation_decrease DOUBLE PRECISION DEFAULT 0.1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config DROP manager_sacking_win_ratio_trigger');
        $this->addSql('ALTER TABLE game_config DROP manager_sacking_win_ratio_recovery');
        $this->addSql('ALTER TABLE game_config DROP manager_sacking_min_games');
        $this->addSql('ALTER TABLE game_config DROP manager_sacking_attendance_penalty_per_week');
        $this->addSql('ALTER TABLE starter_config DROP fan_base_ranges');
        $this->addSql('ALTER TABLE starter_config DROP fan_base_promotion_increase');
        $this->addSql('ALTER TABLE starter_config DROP fan_base_relegation_decrease');
    }
}
