<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260414120935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE starter_config ALTER default_facilities DROP DEFAULT');
        $this->addSql('ALTER TABLE starter_config ALTER starter_reputation_tier DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE starter_config ALTER default_facilities SET DEFAULT \'{}\'');
        $this->addSql('ALTER TABLE starter_config ALTER starter_reputation_tier SET DEFAULT \'local\'');
    }
}
