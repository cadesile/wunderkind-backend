<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Youth-draw home-nationality mix moves from code to pool_config.
 *
 * Was a private const in YouthPoolService; now four per-tier percentage columns so the
 * GET /api/players/youth intake mix can be retuned from the Player Pool admin screen
 * without a deploy, alongside the youth ability ceilings added in Version20261015072452.
 *
 * Defaults match the constant they replace (95/85/70/45), so behaviour is unchanged until
 * someone edits them.
 */
final class Version20261015093949 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move youth-draw home-nationality percentages to pool_config.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pool_config ADD youth_home_nationality_percent_local INT DEFAULT 95 NOT NULL');
        $this->addSql('ALTER TABLE pool_config ADD youth_home_nationality_percent_regional INT DEFAULT 85 NOT NULL');
        $this->addSql('ALTER TABLE pool_config ADD youth_home_nationality_percent_national INT DEFAULT 70 NOT NULL');
        $this->addSql('ALTER TABLE pool_config ADD youth_home_nationality_percent_elite INT DEFAULT 45 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pool_config DROP youth_home_nationality_percent_local');
        $this->addSql('ALTER TABLE pool_config DROP youth_home_nationality_percent_regional');
        $this->addSql('ALTER TABLE pool_config DROP youth_home_nationality_percent_national');
        $this->addSql('ALTER TABLE pool_config DROP youth_home_nationality_percent_elite');
    }
}
