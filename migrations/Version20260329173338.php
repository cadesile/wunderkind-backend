<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260329173338 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config ADD player_fee_multiplier DOUBLE PRECISION NOT NULL DEFAULT 1000.0');
        $this->addSql('ALTER TABLE game_config ALTER player_fee_multiplier DROP DEFAULT');
        $this->addSql('ALTER TABLE pool_config ALTER agent_pool_target DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_config DROP player_fee_multiplier');
        $this->addSql('ALTER TABLE pool_config ALTER agent_pool_target SET DEFAULT 20');
    }
}
