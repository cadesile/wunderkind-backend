<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds GameConfig::losingTeamCardMultiplierMax/losingTeamCardGoalDiffCap —
 * config knobs for DeterministicEngine's ResultsEngine.ts-derived card model.
 *
 * `doctrine:migrations:diff` also proposed the same unrelated pre-existing
 * schema drift (hand-added partial unique indexes, DROP DEFAULT/rename noise)
 * documented in earlier migrations in this file — none of that belongs here,
 * so only the two new columns are kept.
 */
final class Version20260919181112 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add GameConfig losingTeamCardMultiplierMax/losingTeamCardGoalDiffCap';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD losing_team_card_multiplier_max DOUBLE PRECISION DEFAULT 1.5 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD losing_team_card_goal_diff_cap INT DEFAULT 3 NOT NULL');
        $this->addSql('ALTER TABLE game_config ALTER losing_team_card_multiplier_max DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ALTER losing_team_card_goal_diff_cap DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP losing_team_card_multiplier_max');
        $this->addSql('ALTER TABLE game_config DROP losing_team_card_goal_diff_cap');
    }
}
