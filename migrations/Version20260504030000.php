<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Transfer history: make club_id nullable, add npc_club_id FK, add player_name/player_position/club_leaving snapshot columns';
    }

    public function up(Schema $schema): void
    {
        // Make club_id nullable (supports NPC-only transfer records)
        $this->addSql('ALTER TABLE transfer ALTER COLUMN club_id DROP NOT NULL');

        // Add NPC club FK
        $this->addSql('ALTER TABLE transfer ADD COLUMN npc_club_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE transfer ADD CONSTRAINT fk_transfer_npc_club FOREIGN KEY (npc_club_id) REFERENCES npc_club (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_transfer_npc_club_occurred ON transfer (npc_club_id, occurred_at)');

        // Add player/club snapshot columns
        $this->addSql('ALTER TABLE transfer ADD COLUMN player_name    VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE transfer ADD COLUMN player_position VARCHAR(10)  DEFAULT NULL');
        $this->addSql('ALTER TABLE transfer ADD COLUMN club_leaving   VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_transfer_npc_club_occurred');
        $this->addSql('ALTER TABLE transfer DROP CONSTRAINT IF EXISTS fk_transfer_npc_club');
        $this->addSql('ALTER TABLE transfer DROP COLUMN IF EXISTS npc_club_id');
        $this->addSql('ALTER TABLE transfer DROP COLUMN IF EXISTS player_name');
        $this->addSql('ALTER TABLE transfer DROP COLUMN IF EXISTS player_position');
        $this->addSql('ALTER TABLE transfer DROP COLUMN IF EXISTS club_leaving');
        $this->addSql('ALTER TABLE transfer ALTER COLUMN club_id SET NOT NULL');
    }
}
