<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260419160039 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player ADD personality_determination SMALLINT DEFAULT 10 NOT NULL');
        $this->addSql('ALTER TABLE player ADD personality_professionalism SMALLINT DEFAULT 10 NOT NULL');
        $this->addSql('ALTER TABLE player ADD personality_ambition SMALLINT DEFAULT 10 NOT NULL');
        $this->addSql('ALTER TABLE player ADD personality_adaptability SMALLINT DEFAULT 10 NOT NULL');
        $this->addSql('ALTER TABLE player ADD personality_pressure SMALLINT DEFAULT 10 NOT NULL');
        $this->addSql('ALTER TABLE player ADD personality_temperament SMALLINT DEFAULT 10 NOT NULL');
        $this->addSql('ALTER TABLE player ADD personality_consistency SMALLINT DEFAULT 10 NOT NULL');
        $this->addSql('ALTER TABLE player DROP personality_confidence');
        $this->addSql('ALTER TABLE player DROP personality_maturity');
        $this->addSql('ALTER TABLE player DROP personality_teamwork');
        $this->addSql('ALTER TABLE player DROP personality_leadership');
        $this->addSql('ALTER TABLE player DROP personality_ego');
        $this->addSql('ALTER TABLE player DROP personality_bravery');
        $this->addSql('ALTER TABLE player DROP personality_greed');
        $this->addSql('ALTER TABLE player ALTER personality_loyalty SET DEFAULT 10');
        $this->addSql('ALTER TABLE starter_config ALTER league_ability_ranges DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE player ADD personality_confidence SMALLINT DEFAULT 50 NOT NULL');
        $this->addSql('ALTER TABLE player ADD personality_maturity SMALLINT DEFAULT 50 NOT NULL');
        $this->addSql('ALTER TABLE player ADD personality_teamwork SMALLINT DEFAULT 50 NOT NULL');
        $this->addSql('ALTER TABLE player ADD personality_leadership SMALLINT DEFAULT 50 NOT NULL');
        $this->addSql('ALTER TABLE player ADD personality_ego SMALLINT DEFAULT 50 NOT NULL');
        $this->addSql('ALTER TABLE player ADD personality_bravery SMALLINT DEFAULT 50 NOT NULL');
        $this->addSql('ALTER TABLE player ADD personality_greed SMALLINT DEFAULT 50 NOT NULL');
        $this->addSql('ALTER TABLE player DROP personality_determination');
        $this->addSql('ALTER TABLE player DROP personality_professionalism');
        $this->addSql('ALTER TABLE player DROP personality_ambition');
        $this->addSql('ALTER TABLE player DROP personality_adaptability');
        $this->addSql('ALTER TABLE player DROP personality_pressure');
        $this->addSql('ALTER TABLE player DROP personality_temperament');
        $this->addSql('ALTER TABLE player DROP personality_consistency');
        $this->addSql('ALTER TABLE player ALTER personality_loyalty SET DEFAULT 50');
        $this->addSql('ALTER TABLE starter_config ALTER league_ability_ranges SET DEFAULT \'[]\'');
    }
}
