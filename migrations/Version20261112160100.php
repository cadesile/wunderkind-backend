<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The economy model's knobs, every one of them defaulting to "behave exactly as before".
 *
 * NINE COLUMNS AND A PROVABLE NO-OP. `economy_wage_model` is `legacy`, every tier factor is
 * 10000 (parity) and `inflation_rate_per_season_bp` is 0, so applying this cannot move a
 * generated figure or a published value. Turning any of it on is a later operator action with
 * a calibration preview in front of it.
 *
 * `economy_baseline_turnover_pence` SHIPS AS EIGHT ZEROS, WHICH IS NOT A USABLE CONFIGURATION
 * and is deliberately not a harmless one. The wage scale divides by turnover, so zero makes the
 * turnover model undefined rather than cheap — EconomyScaleResolver refuses an unauthored row
 * instead of dividing by it, which is what makes the turnover model unreachable until somebody
 * fills the table in. A plausible-looking default here would be the dangerous choice: it would
 * let the model be switched on against numbers nobody authored.
 *
 * JSON, NOT JSONB, matching every other config array on this table (leagueTierDefaults,
 * npcClubBalanceRanges, bbox, the naming lists) so migrations:diff stays quiet. Nothing queries
 * into these; they are read whole and indexed by tier.
 *
 * BASIS POINTS EVEN FOR THE CURVE EXPONENT (25000 = 2.5), which looks odd and is still right:
 * it keeps the admin form and the config export dealing in integers like every other figure
 * here, and confines the one float to the single pow() inside the resolver, which is also the
 * only place the chain rounds.
 *
 * WEEKS PER SEASON IS 46, NOT 52. The reference-squad calibration only means anything if the
 * number of weeks the server divides by is the number the device actually charges for, and the
 * app's paid season is 46 weeks. The backend's own financial year is 52
 * (Club::crossedFinancialYearEnd()), so six weeks of it are unpriced by this model — recorded
 * on the column rather than reconciled, because the two numbers measure different things.
 *
 * Reversible: nine plain drops.
 */
final class Version20261112160100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the economy model configuration to game_config, every column defaulting to no change.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE game_config ADD economy_wage_model VARCHAR(16) DEFAULT 'legacy' NOT NULL");
        // ADDED WITH A DEFAULT, THEN THE DEFAULT IS DROPPED, AND BOTH HALVES ARE NECESSARY.
        // game_config is a populated singleton, so a NOT NULL column cannot be added without
        // something to put in the existing row. But a json column that KEEPS a database-side
        // default is drift the mapping does not declare, and Version20261011134810 exists
        // precisely because six json columns were added this way and left that way —
        // migrations:diff then proposed re-dropping those defaults on every unrelated change.
        // Populate from the default, then drop it, so the column matches the mapping the moment
        // this migration finishes.
        $this->addSql("ALTER TABLE game_config ADD economy_tier_factors_bp JSON DEFAULT '[10000,10000,10000,10000,10000,10000,10000,10000]' NOT NULL");
        $this->addSql('ALTER TABLE game_config ALTER economy_tier_factors_bp DROP DEFAULT');
        $this->addSql("ALTER TABLE game_config ADD economy_baseline_turnover_pence JSON DEFAULT '[0,0,0,0,0,0,0,0]' NOT NULL");
        $this->addSql('ALTER TABLE game_config ALTER economy_baseline_turnover_pence DROP DEFAULT');
        $this->addSql('ALTER TABLE game_config ADD economy_wage_to_turnover_ratio_bp INT DEFAULT 6000 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD economy_wage_curve_exponent_bp INT DEFAULT 25000 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD economy_staff_wage_share_bp INT DEFAULT 2500 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD economy_weeks_per_season SMALLINT DEFAULT 46 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD economy_reputation_spread_bp INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE game_config ADD inflation_rate_per_season_bp INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_config DROP inflation_rate_per_season_bp');
        $this->addSql('ALTER TABLE game_config DROP economy_reputation_spread_bp');
        $this->addSql('ALTER TABLE game_config DROP economy_weeks_per_season');
        $this->addSql('ALTER TABLE game_config DROP economy_staff_wage_share_bp');
        $this->addSql('ALTER TABLE game_config DROP economy_wage_curve_exponent_bp');
        $this->addSql('ALTER TABLE game_config DROP economy_wage_to_turnover_ratio_bp');
        $this->addSql('ALTER TABLE game_config DROP economy_baseline_turnover_pence');
        $this->addSql('ALTER TABLE game_config DROP economy_tier_factors_bp');
        $this->addSql('ALTER TABLE game_config DROP economy_wage_model');
    }
}
