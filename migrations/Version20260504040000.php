<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove npc_club_id FK from transfer — NPC clubs do not store transfer history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_transfer_npc_club_occurred');
        $this->addSql('ALTER TABLE transfer DROP CONSTRAINT IF EXISTS fk_transfer_npc_club');
        $this->addSql('ALTER TABLE transfer DROP COLUMN IF EXISTS npc_club_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transfer ADD COLUMN npc_club_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE transfer ADD CONSTRAINT fk_transfer_npc_club FOREIGN KEY (npc_club_id) REFERENCES npc_club (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_transfer_npc_club_occurred ON transfer (npc_club_id, occurred_at)');
    }
}
