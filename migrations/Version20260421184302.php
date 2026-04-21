<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260421184302 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pool_config ADD assistant_coach_pool_target INT NOT NULL DEFAULT 5');
        $this->addSql('ALTER TABLE pool_config ADD manager_pool_target INT NOT NULL DEFAULT 5');
        $this->addSql('ALTER TABLE pool_config ADD director_of_football_pool_target INT NOT NULL DEFAULT 2');
        $this->addSql('ALTER TABLE pool_config ADD facility_manager_pool_target INT NOT NULL DEFAULT 3');
        $this->addSql('ALTER TABLE pool_config ADD chairman_pool_target INT NOT NULL DEFAULT 2');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pool_config DROP assistant_coach_pool_target');
        $this->addSql('ALTER TABLE pool_config DROP manager_pool_target');
        $this->addSql('ALTER TABLE pool_config DROP director_of_football_pool_target');
        $this->addSql('ALTER TABLE pool_config DROP facility_manager_pool_target');
        $this->addSql('ALTER TABLE pool_config DROP chairman_pool_target');
    }
}
