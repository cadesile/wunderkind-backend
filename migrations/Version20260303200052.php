<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260303200052 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE academy CHANGE balance balance INT NOT NULL');
        $this->addSql('ALTER TABLE player CHANGE morale morale INT NOT NULL');
        $this->addSql('ALTER TABLE staff CHANGE morale morale INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE academy CHANGE balance balance INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE player CHANGE morale morale INT DEFAULT 50 NOT NULL');
        $this->addSql('ALTER TABLE staff CHANGE morale morale INT DEFAULT 50 NOT NULL');
    }
}
