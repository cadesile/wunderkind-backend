<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the dead, unused GameConfig::$npcSquadConfig column. It was never read
 * anywhere in the codebase and had a different, stale shape from the live
 * config that actually drives NPC generation (StarterConfig::$npcSquadConfig).
 */
final class Version20260927181328 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the dead, unused game_config.npc_squad_config column';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP npc_squad_config');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD npc_squad_config JSON NOT NULL');
    }
}
