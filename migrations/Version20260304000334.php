<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260304000334 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add game_event_template table and manager personality fields to academy';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE game_event_template (id BINARY(16) NOT NULL, slug VARCHAR(100) NOT NULL, category VARCHAR(30) NOT NULL, weight INT UNSIGNED DEFAULT 1 NOT NULL, title VARCHAR(255) NOT NULL, body_template LONGTEXT NOT NULL, impacts JSON NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_B46AA1F989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE academy ADD pa_name VARCHAR(100) DEFAULT NULL, ADD manager_temperament SMALLINT UNSIGNED DEFAULT 50 NOT NULL, ADD manager_discipline SMALLINT UNSIGNED DEFAULT 50 NOT NULL, ADD manager_ambition SMALLINT UNSIGNED DEFAULT 50 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE game_event_template');
        $this->addSql('ALTER TABLE academy DROP pa_name, DROP manager_temperament, DROP manager_discipline, DROP manager_ambition');
    }
}
