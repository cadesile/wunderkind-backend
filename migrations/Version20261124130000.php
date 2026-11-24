<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Follow-up to Version20261124120000: drop the DB default on
 * game_config.motivation_game_time_penalty_weight_by_role.
 *
 * The default was only there so existing rows got the seed when the NOT NULL column was added.
 * GameConfig::$motivationGameTimePenaltyWeightByRole declares no `options: ['default' => …]`,
 * so leaving it on makes SchemaIsInSyncTest / migrations:diff report
 * `ALTER ... DROP DEFAULT` outstanding. A separate migration (rather than editing the first)
 * so databases that already ran Version20261124120000 get the fix too.
 */
final class Version20261124130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the DB default on game_config.motivation_game_time_penalty_weight_by_role to match the entity.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ALTER motivation_game_time_penalty_weight_by_role DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_config ALTER motivation_game_time_penalty_weight_by_role SET DEFAULT '{\"star_player\":1,\"important\":1,\"first_team\":1,\"squad_player\":0.5,\"backup\":0,\"prospect\":0}'");
    }
}
