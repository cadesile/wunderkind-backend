<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260418121622 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pool_config DROP senior_player_age_min');
        $this->addSql('ALTER TABLE pool_config DROP senior_player_age_max');
        $this->addSql('ALTER TABLE pool_config DROP senior_player_ability_min');
        $this->addSql('ALTER TABLE pool_config DROP senior_player_ability_max');
        $this->addSql('ALTER TABLE pool_config DROP senior_player_pool_target');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pool_config ADD senior_player_age_min INT NOT NULL');
        $this->addSql('ALTER TABLE pool_config ADD senior_player_age_max INT NOT NULL');
        $this->addSql('ALTER TABLE pool_config ADD senior_player_ability_min INT NOT NULL');
        $this->addSql('ALTER TABLE pool_config ADD senior_player_ability_max INT NOT NULL');
        $this->addSql('ALTER TABLE pool_config ADD senior_player_pool_target INT NOT NULL');
    }
}
