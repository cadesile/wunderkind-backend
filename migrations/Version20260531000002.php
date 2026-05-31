<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add npc_facility_level_ranges JSON column to game_config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_config ADD COLUMN npc_facility_level_ranges JSONB NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN npc_facility_level_ranges');
    }
}
