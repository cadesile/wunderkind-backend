<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * World pack free agents (Courtney, 2026-11-27).
 *
 * - world_pack_set.free_agents_json: the set's unattached players, served once by
 *   POST /api/initialize/free-agents and cleared by the maintain sweep with the tiers.
 * - starter_config.free_agent_pack_count (200), free_agent_season_top_up_count (80),
 *   free_agent_upper_tier_percent (20).
 */
final class Version20261127150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'World pack free agents: set column + starter config tuning';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE world_pack_set ADD free_agents_json JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE starter_config ADD free_agent_pack_count INT DEFAULT 200 NOT NULL');
        $this->addSql('ALTER TABLE starter_config ADD free_agent_season_top_up_count INT DEFAULT 80 NOT NULL');
        $this->addSql('ALTER TABLE starter_config ADD free_agent_upper_tier_percent INT DEFAULT 20 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE world_pack_set DROP free_agents_json');
        $this->addSql('ALTER TABLE starter_config DROP free_agent_pack_count');
        $this->addSql('ALTER TABLE starter_config DROP free_agent_season_top_up_count');
        $this->addSql('ALTER TABLE starter_config DROP free_agent_upper_tier_percent');
    }
}
