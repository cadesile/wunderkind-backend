<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260423204907 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sponsor and investor offer probability fields (by reputation tier) to game_config';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config ADD sponsor_probability_local DOUBLE PRECISION DEFAULT 0.3 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD sponsor_probability_regional DOUBLE PRECISION DEFAULT 0.2 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD sponsor_probability_national DOUBLE PRECISION DEFAULT 0.1 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD sponsor_probability_elite DOUBLE PRECISION DEFAULT 0.05 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD investor_probability_local DOUBLE PRECISION DEFAULT 0.2 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD investor_probability_regional DOUBLE PRECISION DEFAULT 0.12 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD investor_probability_national DOUBLE PRECISION DEFAULT 0.06 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD investor_probability_elite DOUBLE PRECISION DEFAULT 0.02 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config DROP sponsor_probability_local');
        $this->addSql('ALTER TABLE game_config DROP sponsor_probability_regional');
        $this->addSql('ALTER TABLE game_config DROP sponsor_probability_national');
        $this->addSql('ALTER TABLE game_config DROP sponsor_probability_elite');
        $this->addSql('ALTER TABLE game_config DROP investor_probability_local');
        $this->addSql('ALTER TABLE game_config DROP investor_probability_regional');
        $this->addSql('ALTER TABLE game_config DROP investor_probability_national');
        $this->addSql('ALTER TABLE game_config DROP investor_probability_elite');
    }
}
