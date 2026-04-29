<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260429090303 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add gameplay_effects JSON column to facility_template';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE facility_template ADD gameplay_effects JSON DEFAULT \'{}\' NOT NULL');
        $this->addSql('ALTER TABLE starter_config ALTER starter_manager_count DROP DEFAULT');
        $this->addSql('ALTER TABLE starter_config ALTER starter_director_of_football_count DROP DEFAULT');
        $this->addSql('ALTER TABLE starter_config ALTER starter_facility_manager_count DROP DEFAULT');
        $this->addSql('ALTER TABLE starter_config ALTER starter_chairman_count DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE facility_template DROP gameplay_effects');
        $this->addSql('ALTER TABLE starter_config ALTER starter_manager_count SET DEFAULT 1');
        $this->addSql('ALTER TABLE starter_config ALTER starter_director_of_football_count SET DEFAULT 0');
        $this->addSql('ALTER TABLE starter_config ALTER starter_facility_manager_count SET DEFAULT 0');
        $this->addSql('ALTER TABLE starter_config ALTER starter_chairman_count SET DEFAULT 1');
    }
}
