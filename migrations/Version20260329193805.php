<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260329193805 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config ADD default_morale_min INT NOT NULL DEFAULT 50');
        $this->addSql('ALTER TABLE game_config ALTER default_morale_min DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ADD default_morale_max INT NOT NULL DEFAULT 80');
        $this->addSql('ALTER TABLE game_config ALTER default_morale_max DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config DROP default_morale_min');
        $this->addSql('ALTER TABLE game_config DROP default_morale_max');
    }
}
