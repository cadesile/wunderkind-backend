<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Owner season investment gets its own per-tier curve (Courtney, 2026-11-28). Ships as all
 * nulls, which means "follow the turnover share" for every tier, so deploying it changes no
 * ceiling until a value is typed in Economy settings.
 */
final class Version20261128003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'game_config.economy_season_investment_tier_share_ppm (per-tier owner investment share)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_config ADD economy_season_investment_tier_share_ppm JSON DEFAULT '[null,null,null,null,null,null,null,null]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP economy_season_investment_tier_share_ppm');
    }
}
