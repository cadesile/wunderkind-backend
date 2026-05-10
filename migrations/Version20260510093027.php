<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260510093027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename maxRep → maxAbility in game_config.wage_multiplier_tiers JSON; reset to ability-based defaults';
    }

    public function up(Schema $schema): void
    {
        // Rebuild with ability-based thresholds (25/50/75/null) replacing the old rep-based ones (100/300/600/null)
        $this->addSql("UPDATE game_config SET wage_multiplier_tiers = '[{\"maxAbility\":25,\"playerMultiplier\":0.5,\"staffMultiplier\":0.6},{\"maxAbility\":50,\"playerMultiplier\":1.0,\"staffMultiplier\":1.0},{\"maxAbility\":75,\"playerMultiplier\":2.5,\"staffMultiplier\":2.0},{\"maxAbility\":null,\"playerMultiplier\":5.0,\"staffMultiplier\":4.0}]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE game_config SET wage_multiplier_tiers = '[{\"maxRep\":100,\"playerMultiplier\":0.5,\"staffMultiplier\":0.6},{\"maxRep\":300,\"playerMultiplier\":1.0,\"staffMultiplier\":1.0},{\"maxRep\":600,\"playerMultiplier\":2.5,\"staffMultiplier\":2.0},{\"maxRep\":null,\"playerMultiplier\":5.0,\"staffMultiplier\":4.0}]'");
    }
}
