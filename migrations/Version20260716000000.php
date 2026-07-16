<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add world_pack_players_per_agent to starter_config — target NPC players per
 * agent, used to bound distinct agents surfaced in a generated world pack.
 */
final class Version20260716000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add world_pack_players_per_agent to starter_config (world-pack agent distribution ratio)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE starter_config ADD world_pack_players_per_agent INT NOT NULL DEFAULT 12');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE starter_config DROP world_pack_players_per_agent');
    }
}
