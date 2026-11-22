<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two player transfer dials, so the tier-refusal rule is a config edit rather than an app release.
 *
 * - game_config.player_transfer_out_of_reach_tier_gap (default 3): the tier gap at which a player
 *   at a bigger club refuses outright, whatever the wage.
 * - game_config.player_transfer_wage_premium_per_tier (default 1.0): tempting wage =
 *   base x (1 + tiersBelow x premium).
 *
 * Both defaults match the app's DEFAULT_GAME_CONFIG and the behaviour that shipped hardcoded
 * (2x wage one tier down, 3x two tiers down, hard block at three), so applying this migration
 * changes nothing in play. The DB-level DEFAULT is kept, matching the entity.
 */
final class Version20261122230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add game_config.player_transfer_out_of_reach_tier_gap and player_transfer_wage_premium_per_tier.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD player_transfer_out_of_reach_tier_gap INT DEFAULT 3 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD player_transfer_wage_premium_per_tier DOUBLE PRECISION DEFAULT 1.0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP player_transfer_out_of_reach_tier_gap');
        $this->addSql('ALTER TABLE game_config DROP player_transfer_wage_premium_per_tier');
    }
}
