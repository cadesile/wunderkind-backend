<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260511182946 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config ADD sign_on_fee_percent_min SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD sign_on_fee_percent_max SMALLINT DEFAULT 10 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config DROP sign_on_fee_percent_min');
        $this->addSql('ALTER TABLE game_config DROP sign_on_fee_percent_max');
    }
}
