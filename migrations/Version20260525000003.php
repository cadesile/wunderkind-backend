<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add coach development influence config fields to game_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD coach_development_max_multiplier DOUBLE PRECISION DEFAULT 2.0 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD coach_development_min_specialism INTEGER DEFAULT 20 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD coach_development_stacking_factor DOUBLE PRECISION DEFAULT 0.3 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD coach_morale_influence DOUBLE PRECISION DEFAULT 0.5 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP coach_development_max_multiplier');
        $this->addSql('ALTER TABLE game_config DROP coach_development_min_specialism');
        $this->addSql('ALTER TABLE game_config DROP coach_development_stacking_factor');
        $this->addSql('ALTER TABLE game_config DROP coach_morale_influence');
    }
}
