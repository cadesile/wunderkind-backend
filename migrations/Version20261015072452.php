<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Age-banded youth ability ceilings on pool_config.
 *
 * Caps how much of their potential young players have already realised at generation
 * time (PlayerGenerationService::buildAbilityTarget()), so an academy intake still has
 * its development ahead of it rather than arriving ~40% of the way there.
 *
 * Additive with defaults matching the agreed rule (under 16 -> 10%, under 20 -> 20%), so
 * the existing single config row picks them up without a data migration. Nothing is
 * backfilled: these shape FUTURE generation only, and already-generated pool players keep
 * the ability they were built with. Regenerate the pool to apply them retroactively.
 */
final class Version20261015072452 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add age-banded youth ability ceilings to pool_config.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pool_config ADD player_junior_ability_ceiling_age INT DEFAULT 16 NOT NULL');
        $this->addSql('ALTER TABLE pool_config ADD player_junior_ability_ceiling_percent INT DEFAULT 10 NOT NULL');
        $this->addSql('ALTER TABLE pool_config ADD player_youth_ability_ceiling_age INT DEFAULT 20 NOT NULL');
        $this->addSql('ALTER TABLE pool_config ADD player_youth_ability_ceiling_percent INT DEFAULT 20 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pool_config DROP player_junior_ability_ceiling_age');
        $this->addSql('ALTER TABLE pool_config DROP player_junior_ability_ceiling_percent');
        $this->addSql('ALTER TABLE pool_config DROP player_youth_ability_ceiling_age');
        $this->addSql('ALTER TABLE pool_config DROP player_youth_ability_ceiling_percent');
    }
}
