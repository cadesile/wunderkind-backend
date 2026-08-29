<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Facility staff morale tuning knobs.
 *
 * Facility work only ever moved staff morale downwards: the Facility Manager is
 * docked for running too many jobs and for facilities left to rot, but nothing
 * paid them back for the manager approving and delivering a build. The client
 * now lifts staff morale on both construction start and completion — the FM in
 * full, every other staff member by a share — and these columns are the tuning
 * surface for it.
 *
 * staff_morale_band_factor_mid / _high are the diminishing-returns multipliers
 * applied to non-match staff morale gains, reusing the band bounds already used
 * by the match-result path. Set them to 1.0 to switch diminishing returns off.
 *
 * Defaults here MUST match the GameConfig entity property initialisers and the
 * client's DEFAULT_GAME_CONFIG.
 */
final class Version20260829120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add facility staff morale tuning columns to game_config.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config ADD facility_morale_fm_base                INT DEFAULT 6 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD facility_morale_fm_per_level           INT DEFAULT 2 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD facility_morale_new_build_bonus        INT DEFAULT 4 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD facility_morale_staff_share            DOUBLE PRECISION DEFAULT 0.34 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD facility_morale_start_multiplier       DOUBLE PRECISION DEFAULT 0.4 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD staff_morale_band_factor_mid           DOUBLE PRECISION DEFAULT 0.6 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD staff_morale_band_factor_high          DOUBLE PRECISION DEFAULT 0.25 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS facility_morale_fm_base');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS facility_morale_fm_per_level');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS facility_morale_new_build_bonus');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS facility_morale_staff_share');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS facility_morale_start_multiplier');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS staff_morale_band_factor_mid');
        $this->addSql('ALTER TABLE game_config DROP COLUMN IF EXISTS staff_morale_band_factor_high');
    }
}
