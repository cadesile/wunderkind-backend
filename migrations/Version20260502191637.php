<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260502191637 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config ADD retirement_min_age SMALLINT DEFAULT 16 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD retirement_max_age SMALLINT DEFAULT 21 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD retirement_chance DOUBLE PRECISION DEFAULT 0.5 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config DROP retirement_min_age');
        $this->addSql('ALTER TABLE game_config DROP retirement_max_age');
        $this->addSql('ALTER TABLE game_config DROP retirement_chance');
    }
}
