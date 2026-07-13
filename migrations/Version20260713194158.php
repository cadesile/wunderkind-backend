<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260713194158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agent ADD appearance JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE player ADD appearance JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE scout ADD appearance JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE staff ADD appearance JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agent DROP appearance');
        $this->addSql('ALTER TABLE player DROP appearance');
        $this->addSql('ALTER TABLE scout DROP appearance');
        $this->addSql('ALTER TABLE staff DROP appearance');
    }
}
