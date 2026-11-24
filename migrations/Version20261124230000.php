<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The rest of the morale and motivation dials on game_config.
 *
 * Owner, 2026-11-24: make sure all the motivation related dials are exposed in the admin game
 * config, in a Morale/Motivation section. Version20261124120000 added the drift, weekly tick and
 * promise-lift dials; these are the remaining client keys (morale and motivation effects on
 * development and match performance, kept-promise and contract-breach deltas, clique bleed,
 * staff result morale and manager motivation drift). Every default matches the client's
 * DEFAULT_GAME_CONFIG exactly, so applying this migration changes nothing in game until an admin
 * moves a dial.
 *
 * Additive with defaults, so existing rows take the client values. `down()` drops the columns.
 */
final class Version20261124230000 extends AbstractMigration
{
    private const COLUMNS = [
        'morale_development_threshold_full'          => 'INT DEFAULT 60 NOT NULL',
        'morale_development_threshold_mid'           => 'INT DEFAULT 35 NOT NULL',
        'morale_development_multiplier_mid'          => 'DOUBLE PRECISION DEFAULT 0.6 NOT NULL',
        'morale_development_multiplier_low'          => 'DOUBLE PRECISION DEFAULT 0.2 NOT NULL',
        'motivation_high_threshold'                  => 'INT DEFAULT 75 NOT NULL',
        'motivation_mid_threshold'                   => 'INT DEFAULT 40 NOT NULL',
        'motivation_high_multiplier'                 => 'DOUBLE PRECISION DEFAULT 1.2 NOT NULL',
        'motivation_mid_multiplier'                  => 'DOUBLE PRECISION DEFAULT 1.0 NOT NULL',
        'motivation_low_multiplier'                  => 'DOUBLE PRECISION DEFAULT 0.75 NOT NULL',
        'motivation_performance_high_multiplier'     => 'DOUBLE PRECISION DEFAULT 1.15 NOT NULL',
        'motivation_performance_low_multiplier'      => 'DOUBLE PRECISION DEFAULT 0.85 NOT NULL',
        'motivation_performance_very_low_multiplier' => 'DOUBLE PRECISION DEFAULT 0.7 NOT NULL',
        'gametime_promise_kept_motivation_delta'     => 'INT DEFAULT 8 NOT NULL',
        'gametime_promise_kept_morale_delta'         => 'INT DEFAULT 10 NOT NULL',
        'gametime_promise_broken_morale_delta'       => 'INT DEFAULT -20 NOT NULL',
        'contract_breach_motivation_delta'           => 'INT DEFAULT -2 NOT NULL',
        'clique_motivation_bleed_share'              => 'DOUBLE PRECISION DEFAULT 0.3 NOT NULL',
        'staff_result_morale_win_low'                => 'INT DEFAULT 10 NOT NULL',
        'staff_result_morale_win_mid'                => 'INT DEFAULT 5 NOT NULL',
        'staff_result_morale_win_high'               => 'INT DEFAULT 2 NOT NULL',
        'staff_result_morale_draw_low'               => 'INT DEFAULT 5 NOT NULL',
        'staff_result_morale_draw_mid'               => 'INT DEFAULT 3 NOT NULL',
        'staff_result_morale_draw_high'              => 'INT DEFAULT 1 NOT NULL',
        'staff_result_morale_low_band_max'           => 'INT DEFAULT 50 NOT NULL',
        'staff_result_morale_mid_band_max'           => 'INT DEFAULT 80 NOT NULL',
        'manager_motivation_baseline'                => 'INT DEFAULT 60 NOT NULL',
        'manager_motivation_weekly_drift'            => 'INT DEFAULT 2 NOT NULL',
    ];

    public function getDescription(): string
    {
        return 'Add the remaining Morale / Motivation engine dials to game_config.';
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
