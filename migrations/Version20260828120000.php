<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bond system tuning knobs.
 *
 * Bond decay, the morale consequences of soured relationships, and the gain
 * side (organic growth, AMP support/criticise, training incidents) were all
 * hardcoded in the client engine and unreachable from admin. This lifts the
 * whole set into game_config so the balance is tunable without a release.
 *
 * bond_decay_floor / bond_neglect_weeks / bond_positive_decay_per_week drive
 * new behaviour: positive bonds now erode back toward the floor after going
 * unmaintained. Set bond_positive_decay_per_week to 0 to switch that off.
 *
 * Defaults here MUST match the GameConfig entity property initialisers and the
 * client's DEFAULT_GAME_CONFIG.
 */
final class Version20260828120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bond system tuning columns to game_config.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD bond_min                               INT DEFAULT -100 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD bond_max                               INT DEFAULT 100 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD bond_negative_drift_per_week           INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD bond_positive_decay_per_week           INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD bond_decay_floor                       INT DEFAULT 50 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD bond_neglect_weeks                     INT DEFAULT 8 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD bond_negative_relations_threshold      INT DEFAULT -20 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD bond_morale_decay_chance               DOUBLE PRECISION DEFAULT 0.1 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD bond_morale_decay_penalty              INT DEFAULT 5 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD bond_organic_growth_base_chance        DOUBLE PRECISION DEFAULT 0.05 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD bond_organic_growth_loyalty_divisor    INT DEFAULT 20 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD bond_organic_growth_ability_divisor    INT DEFAULT 100 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD bond_organic_growth_delta              INT DEFAULT 5 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD bond_manager_amp_delta                 INT DEFAULT 5 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD bond_incident_negative_delta           INT DEFAULT -10 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD bond_incident_positive_delta           INT DEFAULT 8 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD coach_manager_trust_threshold          INT DEFAULT -20 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD coach_manager_trust_decay_chance       DOUBLE PRECISION DEFAULT 0.15 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD coach_manager_trust_morale_penalty     INT DEFAULT 5 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS bond_min');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS bond_max');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS bond_negative_drift_per_week');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS bond_positive_decay_per_week');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS bond_decay_floor');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS bond_neglect_weeks');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS bond_negative_relations_threshold');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS bond_morale_decay_chance');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS bond_morale_decay_penalty');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS bond_organic_growth_base_chance');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS bond_organic_growth_loyalty_divisor');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS bond_organic_growth_ability_divisor');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS bond_organic_growth_delta');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS bond_manager_amp_delta');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS bond_incident_negative_delta');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS bond_incident_positive_delta');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS coach_manager_trust_threshold');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS coach_manager_trust_decay_chance');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS coach_manager_trust_morale_penalty');
    }
}
