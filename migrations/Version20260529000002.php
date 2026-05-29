<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add squad role configuration fields to game_config.
 */
final class Version20260529000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add squad_role_appearance_expectations, squad_role_morale_decay_per_week, squad_role_morale_boost_per_week, squad_role_auto_assign_thresholds to game_config';
    }

    public function up(Schema $schema): void
    {
        $appearances = json_encode([
            'star_player' => 30, 'important' => 25, 'first_team' => 20,
            'squad_player' => 12, 'backup' => 5, 'prospect' => 0,
        ]);
        $decay = json_encode([
            'star_player' => -3, 'important' => -2, 'first_team' => -2,
            'squad_player' => -1, 'backup' => 0, 'prospect' => 0,
        ]);
        $boost = json_encode([
            'star_player' => 0, 'important' => 1, 'first_team' => 1,
            'squad_player' => 2, 'backup' => 2, 'prospect' => 3,
        ]);
        $thresholds = json_encode([
            'star_player' => 80, 'important' => 65, 'first_team' => 50,
            'squad_player' => 35, 'backup' => 20, 'prospect' => 0,
        ]);

        $this->addSql("ALTER TABLE game_config ADD squad_role_appearance_expectations JSON DEFAULT '{$appearances}' NOT NULL");
        $this->addSql("ALTER TABLE game_config ADD squad_role_morale_decay_per_week JSON DEFAULT '{$decay}' NOT NULL");
        $this->addSql("ALTER TABLE game_config ADD squad_role_morale_boost_per_week JSON DEFAULT '{$boost}' NOT NULL");
        $this->addSql("ALTER TABLE game_config ADD squad_role_auto_assign_thresholds JSON DEFAULT '{$thresholds}' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN squad_role_appearance_expectations');
        $this->addSql('ALTER TABLE game_config DROP COLUMN squad_role_morale_decay_per_week');
        $this->addSql('ALTER TABLE game_config DROP COLUMN squad_role_morale_boost_per_week');
        $this->addSql('ALTER TABLE game_config DROP COLUMN squad_role_auto_assign_thresholds');
    }
}
