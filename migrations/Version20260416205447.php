<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260416205447 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE academy ADD world_initialized_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE npc_club ADD playing_style VARCHAR(20) DEFAULT \'DIRECT\' NOT NULL');
        $this->addSql('ALTER TABLE npc_club ADD financial_approach VARCHAR(20) DEFAULT \'BALANCED\' NOT NULL');
        $this->addSql('ALTER TABLE npc_club ADD manager_temperament SMALLINT DEFAULT 50 NOT NULL');
        $this->addSql('DROP INDEX idx_player_assigned_at');
        $this->addSql('ALTER TABLE player DROP assigned_at');
        $this->addSql('DROP INDEX idx_staff_assigned_at');
        $this->addSql('ALTER TABLE staff DROP assigned_at');
        $this->addSql("ALTER TABLE starter_config ADD npc_squad_config JSON NOT NULL DEFAULT '{}'");
        $this->addSql('ALTER TABLE starter_config ALTER COLUMN npc_squad_config DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE academy DROP world_initialized_at');
        $this->addSql('ALTER TABLE npc_club DROP playing_style');
        $this->addSql('ALTER TABLE npc_club DROP financial_approach');
        $this->addSql('ALTER TABLE npc_club DROP manager_temperament');
        $this->addSql('ALTER TABLE player ADD assigned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_player_assigned_at ON player (assigned_at)');
        $this->addSql('ALTER TABLE staff ADD assigned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_staff_assigned_at ON staff (assigned_at)');
        $this->addSql('ALTER TABLE starter_config DROP npc_squad_config');
    }
}
