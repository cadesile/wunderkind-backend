<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260325234055 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE starter_config (id INT NOT NULL, starting_balance INT NOT NULL, starter_player_count INT NOT NULL, starter_coach_count INT NOT NULL, starter_scout_count INT NOT NULL, starter_sponsor_tier VARCHAR(20) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE game_config ADD base_xp INT NOT NULL, ADD base_injury_probability DOUBLE PRECISION NOT NULL, ADD regression_upper_threshold INT NOT NULL, ADD regression_lower_threshold INT NOT NULL, ADD reputation_delta_base DOUBLE PRECISION NOT NULL, ADD reputation_delta_facility_multiplier DOUBLE PRECISION NOT NULL, ADD injury_minor_weight INT NOT NULL, ADD injury_moderate_weight INT NOT NULL, ADD injury_serious_weight INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE starter_config');
        $this->addSql('ALTER TABLE game_config DROP base_xp, DROP base_injury_probability, DROP regression_upper_threshold, DROP regression_lower_threshold, DROP reputation_delta_base, DROP reputation_delta_facility_multiplier, DROP injury_minor_weight, DROP injury_moderate_weight, DROP injury_serious_weight');
    }
}
