<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Morale / Motivation dials on game_config.
 *
 * Owner, 2026-11-24: "let's add a Morale/Motivation config section to the Game config in the
 * backend, with dials for these values." Every column is an engine constant the client already
 * has a default for in DEFAULT_GAME_CONFIG; these defaults match those exactly, so applying
 * this migration changes nothing in the game until an admin moves a dial.
 *
 * Additive with defaults, so existing rows take the client values. `down()` drops the columns.
 */
final class Version20261124120000 extends AbstractMigration
{
    private const COLUMNS = [
        'morale_normal_level'                            => 'INT DEFAULT 65 NOT NULL',
        'morale_drift_above_normal_per_week'             => 'DOUBLE PRECISION DEFAULT 0.15 NOT NULL',
        'morale_drift_below_normal_per_week'             => 'DOUBLE PRECISION DEFAULT 0.05 NOT NULL',
        'motivation_reversion_per_week'                  => 'DOUBLE PRECISION DEFAULT 0.12 NOT NULL',
        'motivation_game_time_bonus'                     => 'DOUBLE PRECISION DEFAULT 1.6 NOT NULL',
        'motivation_game_time_bonus_full_share'          => 'DOUBLE PRECISION DEFAULT 0.75 NOT NULL',
        'motivation_team_form_matches'                   => 'INT DEFAULT 5 NOT NULL',
        'motivation_team_form_max_bonus'                 => 'DOUBLE PRECISION DEFAULT 1.5 NOT NULL',
        'motivation_team_form_max_penalty'               => 'DOUBLE PRECISION DEFAULT 1.0 NOT NULL',
        'motivation_team_form_neutral_ppg'               => 'DOUBLE PRECISION DEFAULT 0.3333 NOT NULL',
        'motivation_share_starts_across_squad'           => 'BOOLEAN DEFAULT true NOT NULL',
        'motivation_game_time_penalty_weight_by_role'    => "JSON DEFAULT '{\"star_player\":1,\"important\":1,\"first_team\":1,\"squad_player\":0.5,\"backup\":0,\"prospect\":0}' NOT NULL",
        'player_promise_grant_motivation_min'            => 'INT DEFAULT 30 NOT NULL',
        'player_promise_grant_motivation_max'            => 'INT DEFAULT 40 NOT NULL',
        'gametime_promise_broken_motivation_delta'       => 'INT DEFAULT -40 NOT NULL',
        'player_contract_promise_broken_motivation_delta' => 'INT DEFAULT -40 NOT NULL',
    ];

    public function getDescription(): string
    {
        return 'Add the Morale / Motivation engine dials to game_config.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::COLUMNS as $column => $definition) {
            $this->addSql("ALTER TABLE game_config ADD {$column} {$definition}");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_keys(self::COLUMNS) as $column) {
            $this->addSql("ALTER TABLE game_config DROP {$column}");
        }
    }
}
