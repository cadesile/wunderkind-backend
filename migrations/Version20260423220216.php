<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260423220216 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-club staff limit fields to game_config';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config ADD max_coaches_per_club SMALLINT DEFAULT 15 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD max_managers_per_club SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD max_directors_of_football_per_club SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD max_facility_managers_per_club SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD max_chairmens_per_club SMALLINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD max_scouts_per_club SMALLINT DEFAULT 3 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config DROP max_coaches_per_club');
        $this->addSql('ALTER TABLE game_config DROP max_managers_per_club');
        $this->addSql('ALTER TABLE game_config DROP max_directors_of_football_per_club');
        $this->addSql('ALTER TABLE game_config DROP max_facility_managers_per_club');
        $this->addSql('ALTER TABLE game_config DROP max_chairmens_per_club');
        $this->addSql('ALTER TABLE game_config DROP max_scouts_per_club');
    }
}
